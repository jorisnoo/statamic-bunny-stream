<?php

namespace Noo\BunnyStream\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\BunnyClient;

class SyncVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 130;
    public int $maxExceptions = 5;
    public int $timeout = 3600;
    public bool $failOnTimeout = true;

    public function __construct(public string $assetId, public string $key, public string $generation) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('bunny:'.$this->key))->releaseAfter(30)->expireAfter(3660)];
    }

    public function backoff(): array { return [30, 120, 600, 1800]; }

    private function asset()
    {
        $asset = Streams::find($this->assetId, $this->key);
        return $asset && ($asset->get('bunny_stream')['generation'] ?? null) === $this->generation ? $asset : null;
    }

    public function handle(BunnyClient $bunny): void
    {
        if (! $asset = $this->asset()) {
            return;
        }
        $data = $asset->get('bunny_stream');
        if ($data['state'] === 'ready') {
            return;
        }
        if ($data['library_id'] !== (string) config('statamic.bunny-stream.library_id')) {
            throw new \RuntimeException('Bunny library changed; restore its configuration before retrying.');
        }
        $guid = $data['pending_guid'] ?? null;
        if ($data['state'] === 'remote_unready' && ! empty($data['guid'])) {
            $guid = $data['guid'];
            $data = array_merge($data, ['pending_guid' => $guid, 'state' => 'processing']);
            $asset->set('bunny_stream', $data)->saveQuietly();
        }
        if (! $guid) {
            // A lost create response is ambiguous. Never blindly create another remote video.
            if (($data['state'] ?? null) === 'creating') {
                throw new \RuntimeException('Video creation was interrupted. Find generation '.$this->generation.' in Bunny and attach its GUID before retrying.');
            }
            $asset->set('bunny_stream', array_merge($data, ['state' => 'creating']))->saveQuietly();
            try {
                $guid = $bunny->create($asset->get('title', $asset->basename()).' ['.$this->generation.']')['guid'];
            } catch (\Illuminate\Http\Client\RequestException $e) {
                // Explicit client errors did not create a video; uncertain responses retain the recovery marker.
                if ($e->response->status() < 500 && ! in_array($e->response->status(), [408, 409], true) && $current = $this->asset()) {
                    $current->set('bunny_stream', array_merge($current->get('bunny_stream'), ['state' => 'queued']))->saveQuietly();
                }
                throw $e;
            }
            if (! $asset = $this->asset()) {
                Streams::delete($guid, $data['library_id']);
                return;
            }
            $data = array_merge($asset->get('bunny_stream'), ['pending_guid' => $guid, 'state' => 'uploading']);
            $asset->set('bunny_stream', $data)->saveQuietly();
        }
        if (in_array($data['state'], ['uploading', 'failed', 'queued'], true)) {
            $stream = $asset->stream();
            if (! is_resource($stream)) {
                throw new \RuntimeException('Unable to open the asset source.');
            }
            try {
                $bunny->upload($guid, $stream);
            } finally {
                fclose($stream);
            }
        }
        if (! $asset = $this->asset()) {
            Streams::delete($guid, $data['library_id']);
            return;
        }
        $data = array_merge($asset->get('bunny_stream'), ['state' => 'processing']);
        $asset->set('bunny_stream', $data)->saveQuietly();
        $video = $bunny->video($guid);
        if (! $asset = $this->asset()) {
            Streams::delete($guid, $data['library_id']);
            return;
        }
        $data = $asset->get('bunny_stream');
        $status = (int) ($video['status'] ?? -1);
        if (in_array($status, [5, 6], true)) {
            $asset->set('bunny_stream', array_merge($data, ['state' => 'uploading', 'pending_metadata' => $video]))->saveQuietly();
            throw new \RuntimeException('Bunny rejected or failed to encode the video (status '.$status.').');
        }
        if (in_array($status, [4, 8], true)) {
            $old = $data['guid'] ?? null;
            $data = array_merge($data, ['guid' => $guid, 'pending_guid' => null, 'state' => 'ready', 'metadata' => $video, 'pending_metadata' => null, 'error' => null]);
            $asset->set('bunny_stream', $data)->saveQuietly();
            if ($old && $old !== $guid) {
                Streams::delete($old, $data['library_id']);
            }
        } else {
            $asset->set('bunny_stream', array_merge($data, ['state' => 'processing', 'pending_metadata' => $video, 'error' => null]))->saveQuietly();
            $this->release(60);
        }
    }

    public function failed(?\Throwable $e): void
    {
        if ($asset = $this->asset()) {
            $data = $asset->get('bunny_stream');
            $asset->set('bunny_stream', array_merge($data, ['error' => $e?->getMessage() ?? 'Processing timed out.']))->saveQuietly();
        }
    }
}
