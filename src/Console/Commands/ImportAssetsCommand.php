<?php

namespace Noo\BunnyStream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
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
        $manifest = Journal::read($name);
        $existing = [];
        foreach (Streams::assets() as $asset) {
            $data = $asset->get('bunny_stream', []);
            if (($data['library_id'] ?? null) === $library && isset($data['guid'])) {
                foreach (array_unique(array_merge($data['legacy_guids'] ?? [], [$data['guid']])) as $guid) {
                    if (isset($existing[$guid])) { throw new \RuntimeException('Duplicate assets for GUID '.$guid); }
                    $existing[$guid] = $asset;
                }
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
                    $mapped = $existing[$guid];
                    if (! $this->option('dry-run')) {
                        $manifest[$guid] = array_merge($manifest[$guid] ?? [], ['state' => 'complete', 'asset' => $mapped->id(), 'key' => $mapped->get('bunny_stream')['key']]);
                        Journal::write($name, $manifest);
                    }
                    $this->line('Already imported: '.$mapped->id());
                    continue;
                }
                if ($this->option('dry-run')) { $this->line('Import '.$guid.' — '.($video['title'] ?? 'Untitled')); continue; }
                $temp = tempnam(sys_get_temp_dir(), 'bunny-import-');
                try {
                    $source = 'original';
                    try {
                        $bunny->downloadOriginal($guid, $temp);
                    } catch (RequestException $e) {
                        if (! in_array($e->response->status(), [404], true)) { throw $e; }
                        $source = 'derivative';
                        $bunny->download($guid, $temp, $video);
                    }
                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temp);
                    $extensions = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
                        'video/x-matroska' => 'mkv', 'video/x-msvideo' => 'avi', 'video/mpeg' => 'mpeg', 'video/x-ms-wmv' => 'wmv'];
                    if (! filesize($temp) || ! isset($extensions[$mime])) { throw new \RuntimeException('Download is not a recognized video file.'); }
                    $analysis = (new \getID3)->analyze($temp);
                    if (! empty($analysis['error']) || empty($analysis['video']['resolution_x']) || empty($analysis['playtime_seconds'])) {
                        throw new \RuntimeException('The downloaded video is corrupt or cannot be inspected.');
                    }
                    $path = 'bunny-stream/'.$library.'/'.$guid.'.'.$extensions[$mime];
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
                    $asset->set('title', $video['title'] ?? $guid)->set('bunny_stream', [
                        'key' => (string) Str::uuid(), 'generation' => (string) Str::uuid(), 'library_id' => $library,
                        'guid' => $guid, 'state' => in_array((int) ($video['status'] ?? -1), [4, 8], true) ? 'ready' : 'remote_unready',
                        'source' => $source, 'metadata' => $video,
                    ])->saveQuietly();
                    $existing[$guid] = $asset;
                    $manifest[$guid] = array_merge($manifest[$guid] ?? [], ['state' => 'complete', 'asset' => $asset->id(), 'key' => $asset->get('bunny_stream')['key'], 'source' => $source, 'error' => null]);
                    $this->line('Imported '.$asset->id().' ('.$source.')');
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
}
