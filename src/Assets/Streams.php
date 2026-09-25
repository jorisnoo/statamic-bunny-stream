<?php

namespace Noo\BunnyStream\Assets;

use Illuminate\Support\Str;
use Noo\BunnyStream\Data\BunnyVideo;
use Noo\BunnyStream\Jobs\DeleteVideo;
use Noo\BunnyStream\Jobs\SyncVideo;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

class Streams
{
    public static function enabled($asset): bool
    {
        return $asset->isVideo() && in_array($asset->containerHandle(), config('statamic.bunny-stream.asset_containers', []), true);
    }

    public static function assets(): iterable
    {
        foreach (AssetContainer::all() as $container) {
            yield from $container->queryAssets()->get();
        }
    }

    public static function find(string $id, string $key)
    {
        $asset = Asset::find($id);
        if ($asset && ($asset->get('bunny_stream')['key'] ?? null) === $key) {
            return $asset;
        }
        foreach (self::assets() as $asset) {
            if (($asset->get('bunny_stream')['key'] ?? null) === $key) {
                return $asset;
            }
        }
        return null;
    }

    public static function queue($asset, bool $replace = false): void
    {
        if (! self::enabled($asset)) {
            return;
        }
        self::requireQueue();
        $data = $asset->get('bunny_stream', []);
        if (isset($data['library_id']) && $data['library_id'] !== (string) config('statamic.bunny-stream.library_id')) {
            throw new \RuntimeException('Bunny library changed; refusing to replace an existing stream.');
        }
        if (! $replace && ! empty($data)) {
            return;
        }
        foreach (array_filter([$data['pending_guid'] ?? null]) as $guid) {
            self::delete($guid, $data['library_id']);
        }
        $data = array_merge($data, [
            'key' => $data['key'] ?? (string) Str::uuid(),
            'generation' => (string) Str::uuid(),
            'library_id' => (string) config('statamic.bunny-stream.library_id'),
            'state' => 'queued',
            'pending_guid' => null,
            'error' => null,
            'source' => 'original',
            'legacy_guids' => array_values(array_unique(array_filter(array_merge($data['legacy_guids'] ?? [], [$data['guid'] ?? null])))),
        ]);
        $asset->set('bunny_stream', $data)->saveQuietly();
        self::dispatch($asset);
    }

    public static function dispatch($asset): void
    {
        self::requireQueue();
        $data = $asset->get('bunny_stream');
        SyncVideo::dispatch($asset->id(), $data['key'], $data['generation'])
            ->onQueue(config('statamic.bunny-stream.queue', 'default'));
    }

    public static function requireQueue(): void
    {
        if (in_array(config('queue.connections.'.config('queue.default').'.driver'), [null, 'sync', 'null'], true)) {
            throw new \RuntimeException('Bunny Stream requires a persistent queue connection.');
        }
    }

    public static function delete(?string $guid, string $library): void
    {
        if ($guid && config('statamic.bunny-stream.delete_remote', false)) {
            self::requireQueue();
            // Allow Statamic's replacement/reference events to finish before checking ownership.
            DeleteVideo::dispatch($guid, $library)->delay(now()->addMinute())->onQueue(config('statamic.bunny-stream.queue', 'default'));
        }
    }

    public static function video(?array $data): ?BunnyVideo
    {
        if (empty($data['guid'])) {
            return null;
        }
        return new BunnyVideo($data['guid'], config('statamic.bunny-stream.hostname'), $data['library_id'],
            config('statamic.bunny-stream.token_key'), config('statamic.bunny-stream.token_expiry', 24),
            $data['metadata']['thumbnailFileName'] ?? 'thumbnail.jpg');
    }
}
