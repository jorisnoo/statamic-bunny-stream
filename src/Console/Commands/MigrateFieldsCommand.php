<?php

namespace Noo\BunnyStream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\Migration\Definitions;
use Noo\BunnyStream\Migration\Journal;
use Noo\BunnyStream\Migration\References;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Fieldset;
use Statamic\Facades\YAML;
use Statamic\Listeners\Concerns\GetsItemsContainingData;

class MigrateFieldsCommand extends Command
{
    use GetsItemsContainingData;

    protected $signature = 'bunny-stream:migrate-fields {--dry-run} {--field=* : Raw field handles, or * for every Bunny field} {--container=} {--resume=}';
    protected $description = 'Prepare and convert complete Bunny field batches (requires an editing freeze)';

    public function handle(): int
    {
        if (! $this->option('dry-run') && ! app()->isDownForMaintenance()) {
            $this->error('Enter maintenance mode and stop content writers/queue workers before applying a migration.');
            return self::FAILURE;
        }
        $lock = Cache::lock('bunny-stream:migrate', 3600);
        if (! $lock->get()) { $this->error('Another migration is running.'); return self::FAILURE; }
        try {
            if ($run = $this->option('resume')) {
                if ($this->option('dry-run')) { throw new \RuntimeException('--resume cannot be combined with --dry-run.'); }
                Journal::apply($run);
            } else {
                $run = $this->prepare();
                if (! $run) { return self::SUCCESS; }
                Journal::apply($run);
            }
            $this->call('statamic:stache:clear');
            foreach (Streams::assets() as $asset) { $asset->cacheStore()->forget($asset->metaCacheKey()); }
            if (config('statamic.static_caching.strategy')) { $this->call('statamic:static:clear'); }
            $this->info('Completed '.$run.'. Deploy the matching templates before reopening editing.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally { $lock->release(); }
    }

    private function prepare(): ?string
    {
        $handles = $this->option('field');
        if (! $handles) { throw new \RuntimeException('Select --field=handle (all uses of that handle), or --field=\'*\'.'); }
        $containers = config('statamic.bunny-stream.asset_containers', []);
        $container = $this->option('container') ?: (count($containers) === 1 ? $containers[0] : null);
        if (! $container || ! in_array($container, $containers, true)) { throw new \RuntimeException('Specify an enabled --container.'); }
        $library = (string) config('statamic.bunny-stream.library_id');
        if (! $library) { throw new \RuntimeException('Configure a Bunny library first.'); }
        $items = $this->getItemsContainingData()->values()->collect()->flatMap(function ($item) {
            return $item instanceof \Statamic\Taxonomies\Term ? $item->localizations()->values()->all() : [$item];
        })->merge(iterator_to_array(Streams::assets(), false));
        $blueprints = [];
        foreach ($items as $item) {
            if ($blueprint = $item->blueprint()) { $blueprints[$blueprint->namespace().'/'.$blueprint->handle()] = $blueprint; }
        }
        // Include unused blueprints so their fields do not retain the legacy type.
        $directory = Blueprint::directory();
        if (is_dir($directory)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'yaml') { continue; }
                $handle = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1, -5);
                if ($blueprint = Blueprint::find($handle)) { $blueprints[$blueprint->namespace().'/'.$blueprint->handle()] = $blueprint; }
            }
        }
        $files = [];
        foreach (Fieldset::all() as $fieldset) {
            $fieldset = Fieldset::find($fieldset->handle());
            $this->definition($fieldset, $handles, $container, $library, $files);
        }
        foreach ($blueprints as $blueprint) {
            $this->definition($blueprint, $handles, $container, $library, $files);
        }
        foreach ($blueprints as $blueprint) {
            $namespace = explode('.', $blueprint->namespace() ?? '')[0];
            if (! in_array($namespace, ['collections', 'taxonomies', 'globals', 'assets'], true) && $blueprint->handle() !== 'user'
                && Definitions::hasMigrated($blueprint->contents())) {
                throw new \RuntimeException('Unsupported content owner: '.$blueprint->path().'. Migrate its records before changing this shared definition.');
            }
        }
        // Re-resolve every item blueprint after shared fieldsets have changed in memory.
        foreach ($items as $item) {
            $blueprint = $item->blueprint();
            if (! $blueprint) { continue; }
            Definitions::clearImports($blueprint->contents());
            $blueprint->setContents(Definitions::convert($blueprint->contents(), $handles, $container, $library));
            $copy = clone $item;
            if (References::item($copy)->convert()) {
                $this->content($copy, $files);
            }
            if (method_exists($item, 'workingCopy') && $revision = $item->workingCopy()) {
                $copy = $item->makeFromRevision($revision);
                if (References::item($copy)->convert()) {
                    $revision->attribute('data', $copy->data()->all());
                    $this->content($revision, $files);
                }
            }
        }
        $files = array_values(array_filter($files, fn ($file) => $file['before'] !== $file['after']));
        foreach ($files as $file) { $this->line((isset($file['disk']) ? $file['disk'].'::' : '').$file['path']); }
        $this->info(count($files).' files in this batch. Shared handles and fieldsets include every consumer.');
        if ($this->option('dry-run') || ! $files) { return null; }
        $run = 'migration-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
        Journal::write($run, ['state' => 'prepared', 'library_id' => $library, 'container' => $container, 'files' => $files]);
        $this->info('Journal: '.$run.'; recover using --resume='.$run.' or bunny-stream:rollback '.$run);
        return $run;
    }

    private function definition($definition, array $handles, string $container, string $library, array &$files): void
    {
        $before = $definition->contents();
        Definitions::clearImports($before);
        $after = Definitions::convert($before, $handles, $container, $library);
        if ($after === $before) { return; }
        $path = $definition->initialPath() ?: $definition->path();
        if (! is_file($path) || str_starts_with(realpath($path) ?: $path, realpath(base_path('vendor')).'/')) {
            throw new \RuntimeException('Publish the blueprint/fieldset before migration: '.$path);
        }
        $raw = YAML::parse(file_get_contents($path));
        $files['local:'.$path] = Journal::change(['path' => $path], YAML::dump(array_replace($raw, $after)));
        $definition->setContents($after);
    }

    private function content($item, array &$files): void
    {
        if ($item instanceof AssetContract) {
            $file = ['disk' => $item->container()->diskHandle(), 'path' => $item->metaPath()];
            $meta = YAML::parse(Journal::contents($file));
            $meta['data'] = $item->data()->all();
            $files[$file['disk'].':'.$file['path']] = Journal::change($file, YAML::dump($meta));
        } elseif ($item instanceof \Statamic\Taxonomies\LocalizedTerm) {
            $path = $item->path();
            $key = 'local:'.$path;
            $change = $files[$key] ?? Journal::change(['path' => $path], Journal::contents(['path' => $path]));
            $data = YAML::parse(base64_decode($change['after']));
            if ($item->locale() === $item->term()->defaultLocale()) {
                $data = array_merge($data, $item->data()->all());
            } else {
                $data['localizations'][$item->locale()] = $item->data()->all();
            }
            $change['after'] = base64_encode(YAML::dump($data));
            $files[$key] = $change;
        } else {
            if (! method_exists($item, 'fileContents') || ! is_file($item->path())) {
                throw new \RuntimeException('Unsupported content store for '.get_class($item).'; migrate it through its repository first.');
            }
            $files['local:'.$item->path()] = Journal::change(['path' => $item->path()], $item->fileContents());
        }
    }
}
