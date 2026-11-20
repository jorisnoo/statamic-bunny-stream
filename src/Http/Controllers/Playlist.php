<?php

namespace Noo\BunnyStream\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Serves Bunny's master playlist with the sharpest rendition up to the configured
 * resolution moved to the front. Safari and iOS play HLS natively and start on the
 * first rendition listed, which Bunny orders by encoding (360p first). The other
 * renditions stay, so the player still adapts from there. hls.js sorts renditions
 * by bitrate itself and is unaffected by the order.
 *
 * Variant URIs are made absolute, so segments still stream straight from Bunny.
 * If Bunny can't be reached, the player is redirected to the original playlist.
 */
class Playlist
{
    public function __invoke(string $guid)
    {
        $hostname = config('statamic.bunny-stream.hostname');
        $sourceUrl = "https://{$hostname}/{$guid}/playlist.m3u8";
        $startResolution = (int) config('statamic.bunny-stream.playlist_start_resolution');

        if (! $startResolution) {
            return redirect()->away($sourceUrl);
        }

        $playlist = Cache::remember('bunny:playlist:' . $guid . ':' . $startResolution, now()->addDay(), function () use ($sourceUrl, $startResolution) {
            $response = Http::withHeaders([
                'Referer' => config('app.url'),
            ])->timeout(5)->get($sourceUrl);

            if (! $response->successful() || ! str_starts_with($response->body(), '#EXTM3U')) {
                return null;
            }

            return $this->reorder($response->body(), dirname($sourceUrl), $startResolution);
        });

        if (! $playlist) {
            return redirect()->away($sourceUrl);
        }

        return response($playlist)
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    private function reorder(string $playlist, string $baseUrl, int $startResolution): string
    {
        $header = [];
        $variants = [];
        $lines = preg_split('/\R/', trim($playlist));

        for ($i = 0; $i < count($lines); $i++) {
            if (! str_starts_with($lines[$i], '#EXT-X-STREAM-INF')) {
                $header[] = $lines[$i];

                continue;
            }

            $uri = $lines[++$i] ?? '';

            $variants[] = [
                'info' => $lines[$i - 1],
                'uri' => str_starts_with($uri, 'http') ? $uri : "{$baseUrl}/{$uri}",
                // The short side, so a 720x1280 portrait clip counts as 720p.
                'lines' => preg_match('/RESOLUTION=(\d+)x(\d+)/', $lines[$i - 1], $matches)
                    ? min((int) $matches[1], (int) $matches[2])
                    : 0,
            ];
        }

        if (! $variants) {
            return $playlist;
        }

        $lines = collect($variants)->pluck('lines');
        $withinStart = $lines->filter(fn ($lines) => $lines <= $startResolution);

        $startIndex = $withinStart->isNotEmpty()
            ? $withinStart->sortDesc()->keys()->first()
            : $lines->sort()->keys()->first();

        $start = $variants[$startIndex];
        unset($variants[$startIndex]);

        return collect($header)
            ->filter()
            ->merge(collect([$start, ...$variants])->flatMap(fn ($variant) => [$variant['info'], $variant['uri']]))
            ->implode("\n") . "\n";
    }
}
