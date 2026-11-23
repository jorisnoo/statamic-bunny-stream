<?php

namespace Noo\BunnyStream\Jobs;

use Facades\Statamic\Fields\Validator as FieldValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Validator;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\BunnyClient;
use Statamic\Events\AssetSaved;
use Statamic\Events\AssetUploaded;
use Statamic\Rules\AllowedFile;

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
        return [(new WithoutOverlapping('bunny:'.$this->key))->shared()->releaseAfter(30)->expireAfter(3660)];
    }

    public function backoff(): array { return [30, 120, 600, 1800]; }

    // Waiting for Bunny releases the job; bound it by time, not attempts.
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    // The placeholder as stored now, unless it was deleted, filled, or replaced by a reupload meanwhile.
    private function asset()
    {
        $asset = Streams::find($this->assetId, $this->key);
        $data = $asset?->get('bunny_stream') ?? [];

        return ($data['generation'] ?? null) === $this->generation && ! empty($data['placeholder']) ? $asset : null;
    }

    // Where the source is written before it replaces the placeholder. Statamic lists no files
    // under .meta/ and only treats its .yaml files as metadata.
    private function partPath(): string
    {
        return '.meta/bunny-import-'.$this->key.'.part';
    }

    public function handle(BunnyClient $bunny): void
    {
        if (! $asset = $this->asset()) {
            return;
        }
        $data = $asset->get('bunny_stream');
        if ($data['library_id'] !== (string) config('statamic.bunny-stream.library_id')) {
            throw new \RuntimeException('The streaming library changed; restore its configuration before retrying.');
        }
        $guid = $data['pending_guid'] ?? $data['guid'];
        $video = $bunny->video($guid);
        $status = (int) ($video['status'] ?? -1);
        $ready = Streams::playable($video);
        $failed = in_array($status, [5, 6], true);
        // An original Statamic can't show is replaced by Bunny's MP4, which only exists once
        // encoding is done; don't download the whole original on every poll until then.
        $original = empty($data['derivative']);
        if (! $ready && ! $failed && ! $original) {
            $this->release(60);

            return;
        }
        $temp = tempnam(sys_get_temp_dir(), 'bunny-upload-');
        try {
            try {
                if ($failed) {
                    // Bunny could not encode it; keep the original, if it has one, so Retry can upload it again.
                    $bunny->downloadOriginal($guid, $temp);
                    [$source, $extension] = ['original', Streams::inspect($temp)];
                } else {
                    [$source, $extension] = Streams::fetchSource($bunny, $guid, $temp, $video, $original);
                }
            } catch (\Exception $e) {
                if ($failed) {
                    $this->fail(new \RuntimeException('The video could not be encoded (status '.$status.').'));

                    return;
                }
                // Bunny may not serve the original, or any MP4 rendition, until it has finished encoding.
                if ($ready || ! $e instanceof \RuntimeException) {
                    throw $e;
                }
                $this->release(60);

                return;
            }
            $this->store($temp, $source, $extension, $video, $failed ? $status : null, $ready);
        } finally {
            @unlink($temp);
        }
    }

    private function store(string $temp, string $source, string $extension, array $video, ?int $failedStatus, bool $ready): void
    {
        if (! $asset = $this->asset()) {
            return;
        }
        // Validate the actual stored file with Statamic's native container rules. This is a
        // server-side download, not a PHP HTTP upload; file size and MIME checks still apply.
        $file = new UploadedFile($temp, $asset->filename().'.'.$extension, null, UPLOAD_ERR_OK, true);
        $rules = collect($asset->container()->validationRules())->map(fn ($rule) => FieldValidator::parse($rule))->all();
        Validator::make(['file' => $file], ['file' => array_merge(['file', new AllowedFile], $rules)])->validate();

        $disk = $asset->disk();
        $part = $this->partPath();
        try {
            $stream = fopen($temp, 'rb');
            try {
                if (! $disk->filesystem()->writeStream($part, $stream)) {
                    throw new \RuntimeException('Unable to write asset source.');
                }
            } finally {
                fclose($stream);
            }
            // Writing a large file can take minutes; editors may have deleted, moved or replaced the placeholder.
            if (! $asset = $this->asset()) {
                return;
            }
            $placeholder = $asset->path();
            $placeholderMeta = $asset->metaPath();
            if ($extension === $asset->extension()) {
                if (! $disk->move($part, $placeholder)) {
                    throw new \RuntimeException('Unable to replace the asset placeholder.');
                }
            } else {
                // Bunny served another format: rename the asset only after the source has landed.
                $path = Streams::claimPath($asset->container(), $asset->folder(), $asset->filename(), $extension, function ($path) use ($disk, $part) {
                    if (! $disk->move($part, $path)) {
                        throw new \RuntimeException('Unable to finalize the asset source.');
                    }

                    return $path;
                });
                $asset->hydrate();
                if (! $asset->path($path)->save()) {
                    $disk->delete($path);
                    throw new \RuntimeException('Unable to save the renamed asset.');
                }
            }
        } finally {
            $disk->delete($part);
        }
        // Saving regenerates the file's size, dimensions and duration. Quietly: the saving
        // listener would put back the stored record.
        $data = $asset->get('bunny_stream');
        $guid = $data['pending_guid'] ?? $data['guid'];
        $current = Streams::video($data)?->guid();
        if (! $asset->set('bunny_stream', array_merge($data, [
            'placeholder' => null, 'derivative' => null, 'source' => $source,
            'direct_upload' => true,
            'guid' => $ready ? $guid : ($current ?? $guid),
            'guid_ready' => $ready || $current !== null,
            'pending_guid' => ! $ready && $current !== null ? $guid : null,
            'metadata' => $ready || $current === null ? $video : ($data['metadata'] ?? []),
            'pending_metadata' => ! $ready && $current !== null ? $video : null,
            'state' => $failedStatus ? 'not_synced' : ($ready ? 'ready' : 'remote_unready'),
            'error' => $failedStatus ? 'This video could not be encoded (status '.$failedStatus.'). Retry sync to upload it again.' : null,
        ]))->saveQuietly()) {
            throw new \RuntimeException('Unable to save the imported asset.');
        }
        if ($asset->path() !== $placeholder) {
            $disk->delete($placeholder);
            if ($placeholderMeta !== $placeholder) {
                $disk->delete($placeholderMeta);
            }
        }
        if ($ready && $current && $current !== $guid) {
            Streams::delete($current, $data['library_id']);
        }
        AssetSaved::dispatch($asset);
        AssetUploaded::dispatch($asset, $asset->basename());
        if (! $ready && ! $failedStatus) {
            // Still encoding in Bunny: poll until it is ready.
            Streams::dispatch($asset);
        }
    }

    public function failed(?\Throwable $e): void
    {
        if ($asset = $this->asset()) {
            $asset->disk()->delete($this->partPath());
            $asset->set('bunny_stream', array_merge($asset->get('bunny_stream'), ['error' => $e?->getMessage() ?? 'Import timed out.']))->saveQuietly();
        }
    }
}
