<?php

namespace Noo\BunnyStream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\BunnyClient;

class PruneUploadsCommand extends Command
{
    protected $signature = 'bunny-stream:prune-uploads {--dry-run : List the videos without deleting them}';

    protected $description = 'Delete empty Bunny videos left by uploads that never finished';

    public function handle(BunnyClient $bunny): int
    {
        if (! $bunny->isConfigured()) {
            $this->error('Configure Bunny first.');

            return self::FAILURE;
        }

        // Every GUID and generation an asset still refers to, in any container.
        $owned = [];
        foreach (Streams::assets() as $asset) {
            $data = $asset->get('bunny_stream', []);
            foreach (array_merge($data['legacy_guids'] ?? [], [$data['guid'] ?? null, $data['pending_guid'] ?? null, $data['generation'] ?? null]) as $id) {
                if ($id) {
                    $owned[$id] = true;
                }
            }
        }

        $pruned = 0;
        $cutoff = Carbon::now()->subDays(2);
        foreach ($this->videos($bunny) as $video) {
            // Only videos this addon created for an upload ("<name> [<generation>]") that never received a
            // file (0 = created, 6 = upload failed), well after the upload's one-day ticket expired.
            if (! in_array((int) ($video['status'] ?? -1), [0, 6], true)
                || ! preg_match('/ \[([0-9a-f-]{36})\]$/i', $video['title'] ?? '', $match)
                || isset($owned[$video['guid'] ?? '']) || isset($owned[$match[1]])
                || empty($video['dateUploaded']) || Carbon::parse($video['dateUploaded'])->isAfter($cutoff)) {
                continue;
            }
            $this->line(($this->option('dry-run') ? 'Would delete ' : 'Deleting ').$video['guid'].' ('.$video['title'].')');
            if (! $this->option('dry-run')) {
                $bunny->delete($video['guid']);
            }
            $pruned++;
        }
        $this->info($this->option('dry-run') ? "{$pruned} empty upload(s) found." : "Deleted {$pruned} empty upload(s).");

        return self::SUCCESS;
    }

    private function videos(BunnyClient $bunny): iterable
    {
        $seen = 0;
        for ($page = 1; ; $page++) {
            $response = $bunny->videos($page);
            $items = $response['items'] ?? [];
            yield from $items;
            $seen += count($items);
            if (! $items || $seen >= ($response['totalItems'] ?? 0)) {
                return;
            }
        }
    }
}
