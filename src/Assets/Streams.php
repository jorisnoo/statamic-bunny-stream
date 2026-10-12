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

    // Pass $enabledOnly when only stream-enabled containers matter; ownership checks scan everything.
    public static function assets(bool $enabledOnly = false): iterable
    {
        $handles = config('statamic.bunny-stream.asset_containers', []);
        foreach (AssetContainer::all() as $container) {
            if (! $enabledOnly || in_array($container->handle(), $handles, true)) {
                yield from $container->queryAssets()->get();
            }
        }
    }

    public static function find(string $id, string $key)
    {
        $asset = Asset::find($id);
        if ($asset && ($asset->get('bunny_stream')['key'] ?? null) === $key) {
            return $asset;
        }
        foreach (self::assets(true) as $asset) {
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
        // Give replacement listeners time to merge the previous stream before a worker reads the asset.
        self::dispatch($asset, now()->addSeconds(10));
    }

    // Upload events must not fail after Statamic has stored the file; record the problem for a retry instead.
    public static function queueFromEvent($asset, bool $replace = false): void
    {
        try {
            self::queue($asset, $replace);
        } catch (\RuntimeException $e) {
            report($e);
            $asset->set('bunny_stream', array_merge($asset->get('bunny_stream', []), ['state' => 'not_synced', 'error' => $e->getMessage()]))->saveQuietly();
        }
    }

    public static function dispatch($asset, $delay = null): void
    {
        self::requireQueue();
        $data = $asset->get('bunny_stream');
        SyncVideo::dispatch($asset->id(), $data['key'], $data['generation'])
            ->delay($delay)
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
        return BunnyVideo::make($data['guid'], $data['library_id'] ?? null, $data['metadata']['thumbnailFileName'] ?? 'thumbnail.jpg');
    }
}
