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
        return Cache::remember('bunny:all', now()->addHour(), function () {
            try {
                return $this->client->videos()['items'] ?? [];
            } catch (\Throwable $e) {
                Log::error($e->getMessage());

                return [];
            }
        });
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
