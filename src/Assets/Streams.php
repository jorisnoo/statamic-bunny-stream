<?php

namespace Noo\BunnyStream\Assets;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Noo\BunnyStream\BunnyClient;
use Noo\BunnyStream\Data\BunnyVideo;
use Noo\BunnyStream\Jobs\DeleteVideo;
use Noo\BunnyStream\Jobs\ImportVideo;
use Noo\BunnyStream\Jobs\SyncVideo;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

class Streams
{
    // Sources Statamic treats as video, by MIME type; anything else is kept as Bunny's MP4 rendition.
    public const SOURCE_EXTENSIONS = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
        'video/x-matroska' => 'mkv', 'video/mpeg' => 'mpeg'];

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

    // The stored value, re-read so writes from queue workers since this request loaded the asset win.
    public static function persisted($asset): ?array
    {
        $disk = $asset->disk();
        $path = $asset->metaPath();
        if ($path !== $asset->path()) {
            return $disk->exists($path) ? (\Statamic\Facades\YAML::parse($disk->get($path))['data']['bunny_stream'] ?? null) : null;
        }

        // Database-backed drivers keep no .meta file (the Eloquent driver's metaPath() is the media file).
        // Read the stored row at the path it was loaded from: a save may be moving the asset.
        $stored = self::fresh($asset->containerHandle().'::'.($asset->getOriginal('path') ?? $asset->path()));

        return $stored ? $stored->get('bunny_stream') : $asset->getOriginal('data.bunny_stream');
    }

    /**
     * The asset as stored now. The Eloquent driver keeps every asset it finds for the rest of
     * the request or job, so a plain find() would miss what other processes wrote meanwhile.
     */
    public static function fresh(?string $id)
    {
        if (! $id) {
            return null;
        }
        \Statamic\Facades\Blink::forget('eloquent-asset-'.$id);

        return Asset::find($id);
    }

    public static function find(string $id, string $key)
    {
        // Renames change the id; remember where the stream moved so polls skip the scan.
        foreach (array_unique(array_filter([$id, Cache::get('bunny-stream:asset:'.$key)])) as $candidate) {
            $asset = self::fresh($candidate);
            if ($asset && ($asset->get('bunny_stream')['key'] ?? null) === $key) {
                return $asset;
            }
        }
        foreach (self::assets(true) as $asset) {
            if (($asset->get('bunny_stream')['key'] ?? null) === $key) {
                Cache::put('bunny-stream:asset:'.$key, $asset->id(), now()->addDay());
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
            'placeholder' => null,
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
        // A placeholder still needs its file from Bunny; the import then hands over to SyncVideo.
        $job = empty($data['placeholder']) ? SyncVideo::class : ImportVideo::class;
        $job::dispatch($asset->id(), $data['key'], $data['generation'])
            ->delay($delay)
            ->onQueue(config('statamic.bunny-stream.queue', 'default'));
    }

    public static function requireQueue(): void
    {
        // Jobs wait for Bunny by releasing and delaying themselves, which sync-style drivers ignore.
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        if (in_array($driver, [null, 'sync', 'null', 'deferred', 'background'], true)
            || app('queue')->connection() instanceof \Illuminate\Queue\SyncQueue) {
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

    /**
     * Download a video's source from Bunny into $temp: the original when Bunny kept a file
     * Statamic treats as video, otherwise the best MP4 rendition.
     *
     * @return array{string, string} provenance ('original' or 'derivative') and file extension
     */
    public static function fetchSource(BunnyClient $bunny, string $guid, string $temp, array $video, bool $original = true): array
    {
        // $original = false skips an original known to be unusable (e.g. an AVI upload).
        if ($original) {
            $source = $bunny->downloadSource($guid, $temp, $video);
        } else {
            $bunny->download($guid, $temp, $video);
            $source = 'derivative';
        }
        if ($source === 'original') {
            try {
                return [$source, self::inspect($temp)];
            } catch (\RuntimeException) {
                // e.g. an AVI or WMV original, or one getID3 can't read: keep Bunny's MP4 rendition instead.
                $bunny->download($guid, $temp, $video);
                $source = 'derivative';
            }
        }

        return [$source, self::inspect($temp)];
    }

    // The extension of a downloaded video Statamic can show, so the asset editor gets the stream.
    public static function inspect(string $file): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (! filesize($file) || ! isset(self::SOURCE_EXTENSIONS[$mime])) {
            throw new \RuntimeException('Download is not a supported video file ('.$mime.'). Statamic recognizes '.implode(', ', \Statamic\Assets\Asset::VIDEO_EXTENSIONS).'.');
        }
        $analysis = (new \getID3)->analyze($file);
        if (! empty($analysis['error']) || empty($analysis['video']['resolution_x']) || empty($analysis['playtime_seconds'])) {
            throw new \RuntimeException('The downloaded video is corrupt or cannot be inspected.');
        }

        return self::SOURCE_EXTENSIONS[$mime];
    }

    /**
     * Claim a free path in $folder for a new file, numbering the name when it is taken, and
     * run $write with it. Uploads finishing at once never get the same path.
     */
    public static function claimPath($container, string $folder, string $name, string $extension, callable $write)
    {
        $folder = $folder === '.' ? '' : trim($folder, '/');

        return Cache::lock('bunny-stream:paths:'.$container->handle(), 120)->block(60, function () use ($container, $folder, $name, $extension, $write) {
            $disk = $container->disk();
            for ($i = 0; ; $i++) {
                $path = ltrim($folder.'/'.$name.($i ? '-'.$i : '').'.'.$extension, '/');
                if (! $disk->exists($path) && ! \Statamic\Facades\Asset::find($container->handle().'::'.$path)) {
                    return $write($path);
                }
            }
        });
    }

    public static function video(?array $data): ?BunnyVideo
    {
        if (empty($data['guid'])) {
            return null;
        }
        return BunnyVideo::make($data['guid'], $data['library_id'] ?? null, $data['metadata']['thumbnailFileName'] ?? 'thumbnail.jpg');
    }
}
