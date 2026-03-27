<?php

namespace Noo\BunnyStream\Repositories;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VideoRepository
{
    public function fetchAll(): array
    {
        return Cache::remember('bunny:all', now()->addHour(), function () {
            try {
                $result = Http::withHeaders([
                    'Accept'    => 'application/json',
                    'AccessKey' => config('statamic.bunny-stream.api_key'),
                ])->get(vsprintf('https://video.bunnycdn.com/library/%s/videos', [
                    config('statamic.bunny-stream.library_id'),
                ]), [
                    'page'         => 1,
                    'itemsPerPage' => 100,
                    'orderBy'      => 'date',
                ]);

                if (! $result->successful()) {
                    return [];
                }
            } catch (\Throwable $e) {
                Log::error($e->getMessage());
                return [];
            }

            return $result->json('items', []);
        });
    }

    public function fetch(string $video): ?array
    {
        $cacheKey = 'bunny:' . $video;

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $result = Http::withHeaders([
                'Accept' => 'application/json',
                'AccessKey' => config('statamic.bunny-stream.api_key'),
            ])->get(vsprintf('https://video.bunnycdn.com/library/%s/videos/%s', [
                config('statamic.bunny-stream.library_id'),
                $video,
            ]));

            if (! $result->successful()) {
                throw new \Exception('Unable to find video.');
            }
        } catch (\Throwable $e) {
            Log::error($e->getMessage());

            return null;
        }

        $data = $result->json();
        Cache::forever($cacheKey, $data);

        return $data;
    }
}
