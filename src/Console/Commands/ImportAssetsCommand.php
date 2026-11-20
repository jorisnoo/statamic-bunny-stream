<?php

namespace Noo\BunnyStream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\BunnyClient;
use Noo\BunnyStream\Migration\Journal;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

class ImportAssetsCommand extends Command
{
    protected $signature = 'bunny-stream:import-assets {container} {--dry-run}';
    protected $description = 'Import existing Bunny videos as assets without creating remote streams';

    public function handle(BunnyClient $bunny): int
    {
        $container = AssetContainer::find($this->argument('container'));
        if (! $container || ! in_array($container->handle(), config('statamic.bunny-stream.asset_containers', []), true) || ! $bunny->isConfigured()) {
            $this->error('Configure Bunny and enable the target asset container first.');
            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            return $this->import($bunny, $container);
        }
        $lock = Cache::lock('bunny-stream:import', 86400);
        if (! $lock->get()) { $this->error('Another import is running.'); return self::FAILURE; }
        try {
            return $this->import($bunny, $container);
        } finally { $lock->release(); }
    }

    private function import(BunnyClient $bunny, $container): int
    {
        $library = (string) config('statamic.bunny-stream.library_id');
        $name = 'imports-'.hash('sha256', $library);
        $dryRun = $this->option('dry-run');
        $manifest = $dryRun ? [] : Journal::read($name);
        $existing = [];
        foreach (Streams::assets() as $asset) {
            $data = Streams::persisted($asset);
            if (! Streams::managed($data) || ($data['library_id'] ?? null) !== $library) {
                continue;
            }
            $guids = array_unique(array_filter(array_merge($data['legacy_guids'] ?? [], [$data['guid'] ?? null, $data['pending_guid'] ?? null])));
            foreach ($guids as $guid) {
                if (isset($existing[$guid]) && $existing[$guid][0]->id() !== $asset->id()) {
                    throw new \RuntimeException('Duplicate assets for GUID '.$guid);
                }
                $existing[$guid] = [$asset, $data];
            }
        }
        $failed = 0;
        $seen = [];
        for ($page = 1; ; $page++) {
            $response = $bunny->videos($page);
            $items = $response['items'] ?? throw new \RuntimeException('Invalid Bunny listing response.');
            foreach ($items as $video) {
                $guid = $video['guid'] ?? '';
                if (! Str::isUuid($guid)) { throw new \RuntimeException('Invalid Bunny GUID.'); }
                if (isset($seen[$guid])) { throw new \RuntimeException('Bunny pagination repeated a video. Retry the import.'); }
                $seen[$guid] = true;
                if (isset($existing[$guid])) {
                    [$mapped, $data] = $existing[$guid];
                    if (! $dryRun && ($guid !== ($data['pending_guid'] ?? null) || $guid === ($data['guid'] ?? null))) {
                        $manifest[$guid] = array_merge($manifest[$guid] ?? [], ['state' => 'complete', 'asset' => $mapped->id(), 'key' => $data['key'] ?? null]);
                        Journal::write($name, $manifest);
                    }
                    if (! $dryRun && $guid === ($data['pending_guid'] ?? $data['guid'] ?? null)) {
                        $this->poll($mapped, $data);
                    }
                    $this->line('Already imported: '.$mapped->id());
                    continue;
                }
                if ($dryRun) { $this->line('Import '.$guid.' — '.($video['title'] ?? 'Untitled')); continue; }
                $temp = tempnam(sys_get_temp_dir(), 'bunny-import-');
                try {
                    [$source, $extension] = Streams::fetchSource($bunny, $guid, $temp, $video);
                    $path = 'bunny-stream/'.$library.'/'.$guid.'.'.$extension;
                    $disk = $container->disk();
                    $checksum = hash_file('sha256', $temp);
                    $record = $manifest[$guid] ?? [];
                    if ($disk->exists($path)) {
                        if (($record['path'] ?? null) !== $path || ($record['checksum'] ?? null) !== $checksum || ($record['container'] ?? null) !== $container->handle()) {
                            throw new \RuntimeException('Refusing to overwrite an existing file: '.$path);
                        }
                        $stream = $disk->filesystem()->readStream($path);
                        $hash = hash_init('sha256');
                        hash_update_stream($hash, $stream);
                        fclose($stream);
                        if (hash_final($hash) !== $checksum) { throw new \RuntimeException('Existing import file changed: '.$path); }
                    } else {
                        $manifest[$guid] = ['state' => 'downloading', 'path' => $path, 'container' => $container->handle(), 'checksum' => $checksum, 'source' => $source];
                        Journal::write($name, $manifest);
                        $stream = fopen($temp, 'rb');
                        $staging = '.bunny-stream-imports/'.$guid.'.part';
                        try {
                            if (! $disk->filesystem()->writeStream($staging, $stream)) { throw new \RuntimeException('Unable to write asset source.'); }
                            if ($disk->exists($path) || ! $disk->move($staging, $path)) { throw new \RuntimeException('Unable to finalize asset source.'); }
                        } finally { fclose($stream); $disk->delete($staging); }
                    }
                    $asset = Asset::make()->container($container)->path($path);
                    $ready = Streams::playable($video);
                    $data = [
                        'key' => (string) Str::uuid(), 'generation' => (string) Str::uuid(), 'library_id' => $library,
                        'guid' => $guid, 'guid_ready' => $ready, 'state' => $ready ? 'ready' : 'remote_unready',
                        'source' => $source, 'metadata' => $video,
                    ];
                    if (! $asset->set('title', $video['title'] ?? $guid)->set('bunny_stream', $data)->saveQuietly()) {
                        throw new \RuntimeException('Unable to save the imported asset.');
                    }
                    $manifest[$guid] = array_merge($manifest[$guid] ?? [], ['state' => 'complete', 'asset' => $asset->id(), 'key' => $data['key'], 'source' => $source, 'error' => null]);
                    $this->line('Imported '.$asset->id().' ('.$source.')');
                    $this->poll($asset, $data);
                } catch (\Throwable $e) {
                    $failed++;
                    $manifest[$guid] = array_merge($manifest[$guid] ?? [], ['error' => $e->getMessage()]);
                    $this->warn($guid.': '.$e->getMessage());
                } finally {
                    unlink($temp);
                    Journal::write($name, $manifest);
                }
            }
            if (count($seen) >= ($response['totalItems'] ?? throw new \RuntimeException('Missing Bunny totalItems.'))) { break; }
            if (! $items) { throw new \RuntimeException('Incomplete Bunny listing.'); }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function poll($asset, array $data): void
    {
        // Also resume older in-place polls whose workers moved them out of remote_unready.
        if (($data['state'] ?? null) !== 'remote_unready'
            && (empty($data['pending_guid']) || $data['pending_guid'] !== ($data['guid'] ?? null))) {
            return;
        }
        try {
            Streams::dispatch($asset);
        } catch (\RuntimeException $e) {
            $this->warn($asset->id().': '.$e->getMessage().' Use Retry sync once the queue runs.');
        }
    }
}
