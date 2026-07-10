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

    public function download(string $guid, string $sinkPath): void
    {
        Http::timeout(0)
            ->connectTimeout(30)
            ->withHeaders(['AccessKey' => $this->apiKey()])
            ->withOptions(['sink' => $sinkPath])
            ->get("https://video.bunnycdn.com/library/{$this->libraryId()}/videos/{$guid}/download")
            ->throw();
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
