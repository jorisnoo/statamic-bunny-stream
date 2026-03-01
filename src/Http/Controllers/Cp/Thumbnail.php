<?php

namespace Noo\BunnyStream\Http\Controllers\Cp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Noo\BunnyStream\Repositories\VideoRepository;
use Statamic\Http\Controllers\CP\CpController;

class Thumbnail extends CpController
{
    public function __invoke(string $guid, VideoRepository $repo)
    {
        $video = $repo->fetch($guid);

        if (! $video || empty($video['thumbnailFileName'])) {
            abort(404);
        }

        $cacheKey = 'bunny:thumb:' . $guid;

        $image = Cache::remember($cacheKey, now()->addHours(24), function () use ($video, $guid) {
            $hostname = config('statamic.bunny-stream.hostname');
            $url = "https://{$hostname}/{$guid}/{$video['thumbnailFileName']}";

            $response = Http::get($url);

            if (! $response->successful()) {
                return null;
            }

            return $response->body();
        });

        if (! $image) {
            abort(404);
        }

        return response($image)
            ->header('Content-Type', 'image/jpeg')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
