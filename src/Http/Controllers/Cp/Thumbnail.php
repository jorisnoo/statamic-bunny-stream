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

        $image = Cache::get($cacheKey);

        if (! $image) {
            $hostname = config('statamic.bunny-stream.hostname');
            $url = "https://{$hostname}/{$guid}/{$video['thumbnailFileName']}";

            $response = Http::withHeaders([
                'Referer' => config('app.url'),
            ])->get($url);

            if (! $response->successful()) {
                abort(404);
            }

            $image = $response->body();
            Cache::put($cacheKey, $image, now()->addHours(24));
        }

        return response($image)
            ->header('Content-Type', 'image/jpeg')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
