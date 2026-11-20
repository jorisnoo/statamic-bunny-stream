<?php

namespace Noo\BunnyStream\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\BunnyClient;
use Statamic\Events\AssetSaved;
use Statamic\Events\AssetUploaded;

/**
 * Fills the placeholder asset of a video the browser uploaded straight to Bunny with its
 * source, so large files never pass through PHP's upload limits.
 */
class ImportVideo implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 5;
    public int $timeout = 3600;
    public bool $failOnTimeout = true;

    public function __construct(public string $assetId, public string $key, public string $generation) {}

    // Shares SyncVideo's lock, so the two never write the same stream record at once.
    public function middleware(): array
    {
        return [(new WithoutOverlapping('bunny:'.$this->key))->releaseAfter(30)->expireAfter(3660)];
    }

    public function backoff(): array { return [30, 120, 600, 1800]; }

    // Waiting for Bunny releases the job; bound it by time, not attempts.
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    // The placeholder, unless it was deleted, filled, or replaced by a reupload meanwhile.
    private function asset()
    {
        $asset = Streams::find($this->assetId, $this->key);
        $data = $asset?->get('bunny_stream') ?? [];

        return ($data['generation'] ?? null) === $this->generation && ! empty($data['placeholder']) ? $asset : null;
    }

    public function handle(BunnyClient $bunny): void
    {
        if (! $asset = $this->asset()) {
            return;
        }
        $guid = $asset->get('bunny_stream')['guid'];
        $video = $bunny->video($guid);
        $status = (int) ($video['status'] ?? -1);
        if (in_array($status, [5, 6], true)) {
            $this->fail(new \RuntimeException('Bunny rejected or failed to encode the video (status '.$status.').'));

            return;
        }
        $ready = in_array($status, [4, 8], true);
        $temp = tempnam(sys_get_temp_dir(), 'bunny-upload-');
        try {
            try {
                [$source, $extension] = Streams::fetchSource($bunny, $guid, $temp, $video);
            } catch (\RuntimeException $e) {
                // Bunny may not serve the original, or any MP4 rendition, until it has finished encoding.
                if ($ready) {
                    throw $e;
                }
                $this->release(60);

                return;
            }
            // The download can take a while; editors may have removed or replaced the placeholder.
            if (! $asset = $this->asset()) {
                return;
            }
            $disk = $asset->disk();
            $placeholder = $asset->path();
            $placeholderMeta = $asset->metaPath();
            $path = $extension === $asset->extension()
                ? $placeholder
                : Streams::availablePath($disk, $asset->folder(), $asset->filename(), $extension);
            $stream = fopen($temp, 'rb');
            try {
                if (! $disk->filesystem()->writeStream($path, $stream)) {
                    throw new \RuntimeException('Unable to write asset source.');
                }
            } finally {
                fclose($stream);
            }
            if ($path !== $placeholder) {
                // Bunny served another format (e.g. its MP4 for a MOV): move the asset, updating references to it.
                $asset->hydrate();
                $asset->path($path)->save();
                $disk->delete($placeholder);
                if ($placeholderMeta !== $placeholder) {
                    $disk->delete($placeholderMeta);
                }
            }
            // Saving regenerates the file's size, dimensions and duration. Quietly: the saving
            // listener would put back the stored record.
            $asset->set('bunny_stream', array_merge($asset->get('bunny_stream'), [
                'placeholder' => null, 'state' => $ready ? 'ready' : 'remote_unready',
                'source' => $source, 'metadata' => $video, 'error' => null,
            ]))->saveQuietly();
            AssetSaved::dispatch($asset);
            AssetUploaded::dispatch($asset, $asset->basename());
            if (! $ready) {
                // Still encoding in Bunny: poll until it is ready.
                Streams::dispatch($asset);
            }
        } finally {
            @unlink($temp);
        }
    }

    public function failed(?\Throwable $e): void
    {
        if ($asset = $this->asset()) {
            $asset->set('bunny_stream', array_merge($asset->get('bunny_stream'), ['error' => $e?->getMessage() ?? 'Import timed out.']))->saveQuietly();
        }
    }
}
