<?php

namespace Noo\BunnyStream\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\BunnyClient;
use Statamic\Events\AssetCreated;
use Statamic\Events\AssetSaved;
use Statamic\Events\AssetUploaded;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

/**
 * Copies a video the browser uploaded straight to Bunny into an asset container,
 * so large files never pass through PHP's upload limits.
 */
class ImportVideo implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 5;
    public int $timeout = 3600;
    public bool $failOnTimeout = true;

    public function __construct(public string $guid, public string $container, public string $folder, public string $basename, public string $generation) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('bunny-import:'.$this->guid))->releaseAfter(30)->expireAfter(3660)];
    }

    public function backoff(): array { return [30, 120, 600, 1800]; }

    // Waiting for Bunny releases the job; bound it by time, not attempts.
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(BunnyClient $bunny): void
    {
        $library = (string) config('statamic.bunny-stream.library_id');
        if (! $container = AssetContainer::find($this->container)) {
            return;
        }
        // A redelivered job must not create a second asset.
        foreach (Streams::assets(true) as $asset) {
            $data = $asset->get('bunny_stream', []);
            if (($data['library_id'] ?? null) === $library && ($data['guid'] ?? null) === $this->guid) {
                return;
            }
        }
        $video = $bunny->video($this->guid);
        $status = (int) ($video['status'] ?? -1);
        if (in_array($status, [5, 6], true)) {
            $this->fail(new \RuntimeException('Bunny rejected or failed to encode the video (status '.$status.').'));

            return;
        }
        $ready = in_array($status, [4, 8], true);
        $temp = tempnam(sys_get_temp_dir(), 'bunny-upload-');
        try {
            try {
                [$source, $extension] = Streams::fetchSource($bunny, $this->guid, $temp, $video);
            } catch (\RuntimeException $e) {
                // Bunny may not serve the original, or any MP4 rendition, until it has finished encoding.
                if ($ready) {
                    throw $e;
                }
                $this->release(60);

                return;
            }
            $disk = $container->disk();
            $name = pathinfo($this->basename, PATHINFO_FILENAME);
            $path = ltrim($this->folder.'/'.$name.'.'.$extension, '/');
            if ($disk->exists($path)) {
                $path = ltrim($this->folder.'/'.$name.'-'.time().'.'.$extension, '/');
            }
            $stream = fopen($temp, 'rb');
            try {
                if (! $disk->filesystem()->writeStream($path, $stream)) {
                    throw new \RuntimeException('Unable to write asset source.');
                }
            } finally {
                fclose($stream);
            }
            $asset = Asset::make()->container($container)->path($path);
            // Saved quietly: the saving listener would discard a stream record on a file without metadata.
            $asset->set('bunny_stream', [
                'key' => (string) Str::uuid(), 'generation' => $this->generation, 'library_id' => $library,
                'guid' => $this->guid, 'state' => $ready ? 'ready' : 'remote_unready',
                'source' => $source, 'metadata' => $video,
            ])->saveQuietly();
            AssetSaved::dispatch($asset);
            AssetUploaded::dispatch($asset, $this->basename);
            AssetCreated::dispatch($asset);
            if (! $ready) {
                // Still encoding in Bunny: poll until it is ready.
                Streams::dispatch($asset);
            }
        } finally {
            @unlink($temp);
        }
    }
}
