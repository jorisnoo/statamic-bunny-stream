<?php

namespace Noo\BunnyStream\Repositories;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Noo\BunnyStream\BunnyClient;

class VideoRepository
{
    public function __construct(private BunnyClient $client)
    {
    }

    public function fetchAll(): array
    {
        try {
            // Throwing inside the callback keeps a failed request out of the cache.
            return Cache::remember('bunny:all', now()->addHour(), function () {
                $videos = [];
                for ($page = 1; ; $page++) {
                    $response = $this->client->videos($page);
                    $items = $response['items'] ?? [];
                    $videos = array_merge($videos, $items);
                    if (! $items || count($videos) >= ($response['totalItems'] ?? 0)) {
                        return $videos;
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return [];
        }
    }

    public function fetch(string $guid): ?array
    {
        $cacheKey = 'bunny:'.$guid;

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $video = $this->client->video($guid);
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return null;
        }

        Cache::forever($cacheKey, $video);

        return $video;
    }

    public function forget(?string $guid = null): void
    {
        if ($guid) {
            Cache::forget('bunny:'.$guid);
            Cache::forget('bunny:thumb:'.$guid);
        }

        Cache::forget('bunny:all');
    }
}
