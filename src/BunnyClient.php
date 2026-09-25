<?php

namespace Noo\BunnyStream;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class BunnyClient
{
    public function isConfigured(): bool
    {
        return $this->libraryId() !== ''
            && $this->apiKey() !== ''
            && config('statamic.bunny-stream.hostname');
    }

    public function videos(int $page = 1, int $perPage = 100, ?string $search = null): array
    {
        return $this->request()->get('videos', array_filter([
            'page' => $page,
            'itemsPerPage' => $perPage,
            'orderBy' => 'date',
            'search' => $search,
        ]))->throw()->json();
    }

    public function video(string $guid): array
    {
        return $this->request()->get("videos/{$guid}")->throw()->json();
    }

    public function create(string $title, int $thumbnailTime = 0): array
    {
        return $this->request()->post('videos', [
            'title' => $title,
            'thumbnailTime' => $thumbnailTime,
        ])->throw()->json();
    }

    public function update(string $guid, array $attributes): void
    {
        $this->request()->post("videos/{$guid}", $attributes)->throw();
    }

    public function delete(string $guid): void
    {
        $this->request()->delete("videos/{$guid}")->throw();
    }

    public function setThumbnail(string $guid, string $contents, string $contentType): void
    {
        $this->request()
            ->withBody($contents, $contentType)
            ->post("videos/{$guid}/thumbnail")
            ->throw();
    }

    public function transcribe(string $guid): void
    {
        $this->request()
            ->post("videos/{$guid}/transcribe", ['generateChapters' => true])
            ->throw();
    }

    public function download(string $guid, string $sinkPath, ?array $video = null): void
    {
        $video ??= $this->video($guid);
        $resolutions = array_filter(explode(',', $video['availableResolutions'] ?? ''), fn ($value) => preg_match('/^\d+p$/', $value));
        usort($resolutions, fn ($a, $b) => (int) $b <=> (int) $a);
        foreach ($resolutions as $resolution) {
            try {
                $this->downloadFile($guid, "play_{$resolution}.mp4", $sinkPath);
                return;
            } catch (\Illuminate\Http\Client\RequestException $e) {
                if ($e->response->status() !== 404) { throw $e; }
            }
        }
        throw new \RuntimeException('No downloadable MP4 is available for '.$guid.'. Supply a source file or enable MP4 fallback in Bunny.');
    }

    public function downloadOriginal(string $guid, string $sinkPath): void
    {
        $this->downloadFile($guid, 'original', $sinkPath);
    }

    private function downloadFile(string $guid, string $filename, string $sinkPath): void
    {
        $path = "/{$guid}/{$filename}";
        $query = [];
        if ($key = config('statamic.bunny-stream.cdn_token_key')) {
            $expires = time() + 3600;
            $query = ['token' => rtrim(strtr(base64_encode(hash('sha256', $key.$path.$expires, true)), '+/', '-_'), '='), 'expires' => $expires];
        }
        $request = Http::timeout(3600)->connectTimeout(30)->withOptions(['sink' => $sinkPath]);
        if ($referer = config('statamic.bunny-stream.download_referer')) { $request->withHeaders(['Referer' => $referer]); }
        $request->get('https://'.config('statamic.bunny-stream.hostname').$path, $query)->throw();
    }

    public function upload(string $guid, $stream): void
    {
        $this->request()->timeout(3500)->connectTimeout(30)
            ->withBody($stream, 'application/octet-stream')
            ->put("videos/{$guid}")->throw();
    }

    /**
     * Presigned authorization for a TUS upload, computed server-side
     * so the API key never reaches the browser.
     */
    public function uploadAuthorization(string $guid): array
    {
        $expires = now()->addDay()->timestamp;

        return [
            'libraryId' => $this->libraryId(),
            'videoId' => $guid,
            'expires' => $expires,
            'signature' => hash('sha256', $this->libraryId().$this->apiKey().$expires.$guid),
        ];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl("https://video.bunnycdn.com/library/{$this->libraryId()}")
            ->acceptJson()
            ->withHeaders(['AccessKey' => $this->apiKey()]);
    }

    private function libraryId(): string
    {
        return (string) config('statamic.bunny-stream.library_id');
    }

    private function apiKey(): string
    {
        return (string) config('statamic.bunny-stream.api_key');
    }
}
