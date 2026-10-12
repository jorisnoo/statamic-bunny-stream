<?php

namespace Noo\BunnyStream\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Noo\BunnyStream\BunnyClient;

class DeleteVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $guid, public string $library) {}

    public function backoff(): array { return [30, 120, 600, 1800]; }

    public function handle(BunnyClient $bunny): void
    {
        if (! config('statamic.bunny-stream.delete_remote', false)) {
            return;
        }
        if ($this->library !== (string) config('statamic.bunny-stream.library_id')) {
            throw new \RuntimeException('Bunny library changed; refusing to delete from another library.');
        }
        foreach (\Noo\BunnyStream\Assets\Streams::assets() as $asset) {
            $data = $asset->get('bunny_stream', []);
            if (($data['library_id'] ?? null) === $this->library && in_array($this->guid, [$data['guid'] ?? null, $data['pending_guid'] ?? null], true)) {
                return;
            }
        }
        try {
            $bunny->delete($this->guid);
        } catch (RequestException $e) {
            if ($e->response->status() !== 404) {
                throw $e;
            }
        }
    }
}
