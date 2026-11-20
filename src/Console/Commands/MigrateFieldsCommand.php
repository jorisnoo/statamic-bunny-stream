<?php

namespace Noo\BunnyStream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\Migration\Definitions;
use Noo\BunnyStream\Migration\FieldsetOverlay;
use Noo\BunnyStream\Migration\Journal;
use Noo\BunnyStream\Migration\References;
use Noo\BunnyStream\Migration\RevisionBlueprint;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\YAML;
use Statamic\Fields\Blueprint as BlueprintDefinition;
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
            FieldsetOverlay::refresh();
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
        $overlay = FieldsetOverlay::install();
        try {
            return $this->prepareBatch($overlay);
        } finally {
            $overlay->uninstall();
        }
    }

    private function prepareBatch(FieldsetOverlay $overlay): ?string
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
            if ($blueprint = $item->blueprint()) {
                $blueprints[$blueprint->fullyQualifiedHandle()] = $blueprint;
            }
        }
        // Include unused blueprints and owner directories separate from the default.
        foreach ($this->blueprintsIn(Blueprint::directory()) as $key => $blueprint) {
            $blueprints[$key] ??= $blueprint;
        }
        foreach ((array) config('statamic.system.blueprints_path') as $namespace => $directory) {
            if (is_string($namespace) && $namespace !== 'default') {
                foreach ($this->blueprintsIn($directory, $namespace.'.') as $key => $blueprint) {
                    $blueprints[$key] ??= $blueprint;
                }
            }
        }
        foreach (Blueprint::getAdditionalNamespaces() as $namespace => $directory) {
            foreach ([$directory, Blueprint::directory().'/vendor/'.str_replace('.', '/', $namespace)] as $path) {
                foreach ($this->blueprintsIn($path, $namespace.'::') as $key => $blueprint) {
                    $blueprints[$key] ??= $blueprint;
                }
            }
        }

        $files = [];
        $overlay->convertUsing(function ($fieldset) use ($handles, $container, $library, &$files) {
            $this->definition($fieldset, $handles, $container, $library, $files);
        });
        foreach (Fieldset::all() as $fieldset) {
            Fieldset::find($fieldset->handle());
        }
        foreach ($blueprints as $key => $blueprint) {
            $blueprint = clone $blueprint;
            $this->definition($blueprint, $handles, $container, $library, $files);
            if (! $this->supportedOwner($blueprint) && Definitions::hasMigrated($blueprint->contents())) {
                throw new \RuntimeException('Unsupported content owner: '.($blueprint->initialPath() ?: $blueprint->path()).'. Migrate its records before changing this shared definition.');
            }
            $blueprints[$key] = $blueprint;
        }
        $this->assertInjects($blueprints);

        $problems = [];
        foreach ($items as $item) {
            $label = $this->describe($item);
            $this->inspect($label, $problems, function () use ($item, $blueprints, &$files) {
                if (! $blueprint = $item->blueprint()) { return; }
                $copy = clone $item;
                if (References::item($copy)->convert($blueprints[$blueprint->fullyQualifiedHandle()] ?? $blueprint)) {
                    $this->content($copy, $files);
                }
            });
            if (method_exists($item, 'workingCopy') && $revision = $item->workingCopy()) {
                $this->inspect($label.' working copy ('.$revision->path().')', $problems, function () use ($item, $revision, $blueprints, &$files) {
                    $blueprint = RevisionBlueprint::resolve($item, $revision);
                    $copy = (clone $item)->data($revision->attributes()['data']);
                    if (References::item($copy)->convert($blueprints[$blueprint->fullyQualifiedHandle()] ?? $blueprint)) {
                        $revision->attribute('data', $copy->data()->all());
                        $this->content($revision, $files);
                    }
                });
            }
        }
        if ($problems) {
            foreach ($problems as $label => $messages) {
                foreach ($messages as $message) { $this->error($label.': '.$message); }
            }
            throw new \RuntimeException(count($problems).' item(s) must be fixed before this batch can migrate. Nothing was written.');
        }
        $files = array_values(array_filter($files, fn ($file) => $file['before'] !== $file['after']));
        foreach ($files as $file) { Journal::assertWritable($file); }
        foreach ($files as $file) { $this->line((isset($file['disk']) ? $file['disk'].'::' : '').$file['path']); }
        $this->info(count($files).' files in this batch. Shared handles and fieldsets include every consumer.');
        if ($this->option('dry-run') || ! $files) { return null; }
        $run = 'migration-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
        Journal::write($run, ['state' => 'prepared', 'library_id' => $library, 'container' => $container, 'files' => $files]);
        $this->info('Journal: '.$run.'; recover using --resume='.$run.' or bunny-stream:rollback '.$run);
        return $run;
    }

    private function blueprintsIn(string $directory, string $prefix = ''): iterable
    {
        if (! is_dir($directory)) { return; }
        $directory = rtrim($directory, '/');
        $files = new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($files) as $file) {
            if ($file->getExtension() !== 'yaml') { continue; }
            $relative = substr($file->getPathname(), strlen($directory) + 1, -5);
            if ($prefix === '' && str_starts_with($relative, 'vendor/')) { continue; }
            $key = $prefix.str_replace('/', '.', $relative);
            if ($blueprint = Blueprint::find($key)) {
                yield (str_contains($key, '::') ? $key : $blueprint->fullyQualifiedHandle()) => $blueprint;
            }
        }
    }

    private function supportedOwner(BlueprintDefinition $blueprint): bool
    {
        $namespace = $blueprint->namespace();
        return ! $blueprint->isNamespaced() && (
            ($namespace === null && $blueprint->handle() === 'user')
            || in_array($namespace, ['globals', 'assets'], true)
            || ($namespace !== null && preg_match('/^(collections|taxonomies)\.[^.]+$/D', $namespace))
        );
    }

    private function assertInjects(array $blueprints): void
    {
        $owners = Collection::all()->keyBy(fn ($collection) => 'collections.'.$collection->handle())
            ->merge(Taxonomy::all()->keyBy(fn ($taxonomy) => 'taxonomies.'.$taxonomy->handle()));
        foreach ($blueprints as $blueprint) {
            if (! $owner = $owners->get($blueprint->namespace())) { continue; }
            $inject = $owner->cascade();
            foreach ($blueprint->fields()->all() as $field) {
                $value = $inject->get($field->handle());
                if ($value !== null && $value !== '' && $value !== [] && Definitions::hasMigrated($field->config())) {
                    throw new \RuntimeException('Unsupported injected value at '.$owner->path().':inject.'.$field->handle().'. Resolve or remove it before running this batch.');
                }
            }
        }
    }

    private function inspect(string $label, array &$problems, \Closure $inspect): void
    {
        try {
            $inspect();
        } catch (\RuntimeException $e) {
            $problems[$label] = explode("\n", $e->getMessage());
        }
    }

    private function definition($definition, array $handles, string $container, string $library, array &$files): void
    {
        $before = $definition->contents();
        $path = $definition->initialPath() ?: $definition->path();
        Definitions::clearImports($before);
        // Convert the file as written. contents() is normalized (legacy `sections:` become `tabs:`)
        // and includes fields Statamic ensures at runtime, neither of which belongs in the YAML.
        $raw = is_file($path) ? YAML::parse(file_get_contents($path)) : null;
        $converted = $raw === null ? null : Definitions::convert($raw, $handles, $container, $library, $path.':');
        $after = Definitions::convert($before, $handles, $container, $library, $path.':');
        if ($after === $before && $converted === $raw) {
            // Statamic may have resolved a blueprint's fields (e.g. user email)
            // before the overlay converted its shared definitions.
            if ($definition instanceof BlueprintDefinition) {
                $definition->setContents($after);
            }
            return;
        }
        if ($raw === null || str_starts_with(realpath($path) ?: $path, realpath(base_path('vendor')).'/')) {
            if ($definition instanceof \Statamic\Fields\Fieldset && $definition->isNamespaced()) {
                throw new \RuntimeException('Override the addon fieldset '.$definition->handle().' before migration by copying '.$path
                    .' to '.Fieldset::overriddenNamespacedFieldsetPath($definition->handle()));
            }
            throw new \RuntimeException('Publish the blueprint/fieldset before migration: '.$path);
        }
        if ($converted !== $raw) {
            $files['local:'.$path] = Journal::change(['path' => $path], YAML::dump($converted));
        }
        $definition->setContents($after);
    }

    private function describe($item): string
    {
        if ($item instanceof AssetContract) { return 'Asset '.$item->id(); }
        $label = class_basename($item).' '.(method_exists($item, 'id') ? $item->id() : '');
        return method_exists($item, 'path') && is_string($path = rescue(fn () => $item->path(), null, false)) ? $label.' ('.$path.')' : $label;
    }

    private function content($item, array &$files): void
    {
        if ($item instanceof AssetContract) {
            // Database-backed assets (the Eloquent driver's metaPath() is the media file) have no .meta file to rewrite.
            if ($item->metaPath() === $item->path()) {
                throw new \RuntimeException('Unsupported content store for '.get_class($item).'; migrate it through its repository first.');
            }
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
