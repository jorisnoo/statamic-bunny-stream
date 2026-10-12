<?php

namespace Noo\BunnyStream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\BunnyClient;

class RepairCommand extends Command
{
    protected $signature = 'bunny-stream:repair {--apply : Write the recovered stream records}';

    protected $description = 'Restore missing Bunny stream records of video assets from the Bunny library';

    public function handle(BunnyClient $bunny): int
    {
        if (! $bunny->isConfigured()) {
            $this->error('Configure Bunny first.');

            return self::FAILURE;
        }
        $library = (string) config('statamic.bunny-stream.library_id');

        // GUIDs owned by another asset are never handed out again.
        $owned = [];
        $missing = [];
        foreach (Streams::assets(true) as $asset) {
            if (! Streams::enabled($asset)) {
                continue;
            }
            $data = $asset->get('bunny_stream', []);
            foreach (array_merge($data['legacy_guids'] ?? [], [$data['guid'] ?? null, $data['pending_guid'] ?? null]) as $guid) {
                if ($guid) {
                    $owned[$guid] = true;
                }
            }
            if (empty($data['guid']) && empty($data['pending_guid'])) {
                $missing[] = $asset;
            }
        }
        if (! $missing) {
            $this->info('Every video asset has its stream record.');

            return self::SUCCESS;
        }

        $videos = [];
        for ($page = 1; ; $page++) {
            $response = $bunny->videos($page);
            $videos = array_merge($videos, $response['items'] ?? []);
            if (! ($response['items'] ?? []) || count($videos) >= ($response['totalItems'] ?? 0)) {
                break;
            }
        }
        $videos = array_filter($videos, fn ($video) => ! isset($owned[$video['guid'] ?? '']));

        $restored = 0;
        foreach ($missing as $asset) {
            $matches = $this->matches($asset, $videos, $library);
            if (count($matches) !== 1) {
                $this->warn($asset->id().': '.(count($matches) ? count($matches).' possible Bunny videos; resolve by hand.' : 'no Bunny video found (use Retry sync to upload it).'));

                continue;
            }
            [$video, $generation] = $matches[0];
            $this->line($asset->id().' → '.$video['guid'].' ('.($video['title'] ?? '').')');
            if (! $this->option('apply')) {
                continue;
            }
            $asset->set('bunny_stream', [
                'key' => (string) Str::uuid(),
                'generation' => $generation ?? (string) Str::uuid(),
                'library_id' => $library,
                'guid' => $video['guid'],
                'state' => in_array((int) ($video['status'] ?? -1), [4, 8], true) ? 'ready' : 'remote_unready',
                'source' => 'original',
                'metadata' => $video,
            ])->saveQuietly();
            $restored++;
        }

        $this->info($this->option('apply') ? "Restored {$restored} stream record(s)." : 'Dry run. Pass --apply to restore the matches listed above.');

        return self::SUCCESS;
    }

    /**
     * Videos that certainly belong to the asset: an import stores the GUID in the path,
     * and the sync job titles each video it creates "<title> [<generation>]".
     */
    private function matches($asset, array $videos, string $library): array
    {
        if (preg_match('#^bunny-stream/'.preg_quote($library, '#').'/([0-9a-f-]{36})\.[a-z0-9]+$#i', $asset->path(), $m)) {
            return array_values(array_map(fn ($video) => [$video, null], array_filter($videos, fn ($video) => ($video['guid'] ?? null) === $m[1])));
        }
        // A surviving generation (e.g. an interrupted create) identifies its video exactly.
        if ($generation = $asset->get('bunny_stream')['generation'] ?? null) {
            return array_values(array_map(fn ($video) => [$video, $generation],
                array_filter($videos, fn ($video) => str_ends_with($video['title'] ?? '', ' ['.$generation.']'))));
        }
        $names = array_unique(array_filter([$asset->get('title'), $asset->basename()]));
        $matches = [];
        foreach ($videos as $video) {
            foreach ($names as $name) {
                if (preg_match('/^'.preg_quote($name, '/').' \[([0-9a-f-]{36})\]$/i', $video['title'] ?? '', $m)) {
                    $matches[$video['guid']] = [$video, $m[1]];
                }
            }
        }

        return array_values($matches);
    }
}
