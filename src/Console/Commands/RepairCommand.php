<?php

namespace Noo\BunnyStream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
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

        // Claim neither current nor historical GUIDs that another asset owns, even outside
        // the enabled containers. Only enabled, locally managed video assets can be repaired.
        $owned = [];
        $missing = [];
        foreach (Streams::assets() as $asset) {
            $data = Streams::persisted($asset);
            if ($data !== null && (! Streams::managed($data) || ($data['library_id'] ?? $library) !== $library)) {
                continue;
            }
            foreach (array_filter(array_merge($data['legacy_guids'] ?? [], [$data['guid'] ?? null, $data['pending_guid'] ?? null])) as $guid) {
                $owned[$guid] = true;
            }
            if (empty($data['pending_guid']) && (empty($data['guid']) || ($data['state'] ?? null) === 'creating') && Streams::enabled($asset)) {
                $missing[$asset->id()] = [$asset, $data];
            }
        }
        if (! $missing) {
            $this->info('Every video asset has its stream record.');

            return self::SUCCESS;
        }

        $available = array_diff_key($this->videos($bunny), $owned);
        $candidates = [];
        $claims = [];
        foreach ($missing as $id => [$asset, $data]) {
            $candidates[$id] = $this->candidates($asset, $data ?? [], $available, $library);
            // Count even candidates of assets that have multiple matches. Resolving one
            // ambiguity must never hand its candidate to another asset by accident.
            foreach (array_keys($candidates[$id]) as $guid) {
                $claims[$guid] = ($claims[$guid] ?? 0) + 1;
            }
        }

        $restored = 0;
        foreach ($missing as $id => [$asset, $data]) {
            $matches = $candidates[$id];
            if (count($matches) !== 1) {
                $this->warn($id.': '.(count($matches) ? count($matches).' possible Bunny videos; resolve by hand.' : 'no Bunny video found (use Retry sync to upload it).'));
                continue;
            }
            $guid = array_key_first($matches);
            if ($claims[$guid] !== 1) {
                $this->warn($id.': Bunny GUID '.$guid.' matches multiple assets; resolve by hand.');
                continue;
            }
            [$generation, $knownGeneration] = $matches[$guid];
            $video = $available[$guid];
            $empty = in_array((int) ($video['status'] ?? -1), [0, 6], true);
            $resumeUpload = $empty && $knownGeneration && ($data['state'] ?? null) === 'creating';
            try {
                $source = $resumeUpload || ($knownGeneration && ! $empty)
                    ? ($data['source'] ?? 'original')
                    : $this->matchingSource($bunny, $asset, $video);
            } catch (\Exception $e) {
                $this->warn($id.': unable to verify the source ('.$e->getMessage().'); left unchanged.');
                continue;
            }
            if ($source === null) {
                $this->warn($id.': current source did not match a downloadable copy of '.$guid.'; left unchanged.');
                continue;
            }
            $this->line($id.' → '.$guid.' ('.($video['title'] ?? '').')'.($resumeUpload ? ' — use Retry sync to upload its source' : ''));
            if (! $this->option('apply')) {
                continue;
            }
            $current = Streams::fresh($id);
            if (! $current || Streams::persisted($current) !== $data) {
                $this->warn($id.': the asset changed while checking its stream; left unchanged.');
                continue;
            }
            $ready = ! $resumeUpload && empty($data['placeholder']) && Streams::playable($video);
            $previous = Streams::video($data)?->guid();
            if (! $current->set('bunny_stream', array_merge($data ?? [], [
                'key' => $data['key'] ?? (string) Str::uuid(),
                'generation' => $generation ?? (string) Str::uuid(),
                'library_id' => $library,
                'guid' => $ready ? $guid : ($previous ?? ($resumeUpload ? null : $guid)),
                'guid_ready' => $ready || $previous !== null,
                'pending_guid' => $resumeUpload || (! $ready && $previous !== null) ? $guid : null,
                'state' => $resumeUpload ? 'uploading' : ($ready ? 'ready' : 'remote_unready'),
                'source' => $source,
                'metadata' => $ready || ($previous === null && ! $resumeUpload) ? $video : ($data['metadata'] ?? null),
                'pending_metadata' => ! $ready && ! $resumeUpload && $previous !== null ? $video : null,
                'error' => null,
            ]))->saveQuietly()) {
                $this->warn($id.': unable to save the recovered stream.');
                continue;
            }
            $restored++;
        }

        $this->info($this->option('apply') ? "Restored {$restored} stream record(s)." : 'Dry run. Pass --apply to restore the matches listed above.');

        return self::SUCCESS;
    }

    private function videos(BunnyClient $bunny): array
    {
        $videos = [];
        for ($page = 1; ; $page++) {
            $response = $bunny->videos($page);
            $items = $response['items'] ?? throw new \RuntimeException('Invalid Bunny listing response.');
            foreach ($items as $video) {
                $guid = $video['guid'] ?? '';
                if (! Str::isUuid($guid) || isset($videos[$guid])) {
                    throw new \RuntimeException('Bunny listing returned an invalid or repeated GUID; retry the repair.');
                }
                $videos[$guid] = $video;
            }
            if (count($videos) >= ($response['totalItems'] ?? throw new \RuntimeException('Missing Bunny totalItems.'))) {
                return $videos;
            }
            if (! $items) {
                throw new \RuntimeException('Incomplete Bunny listing.');
            }
        }
    }

    private function candidates($asset, array $data, array $videos, string $library): array
    {
        $matches = [];
        // A surviving local generation marker identifies the create attempt itself.
        if ($generation = $data['generation'] ?? null) {
            foreach ($videos as $guid => $video) {
                if (str_ends_with($video['title'] ?? '', ' ['.$generation.']')) {
                    $matches[$guid] = [$generation, true];
                }
            }

            return $matches;
        }
        if (preg_match('#^bunny-stream/'.preg_quote($library, '#').'/([0-9a-f-]{36})\.[a-z0-9]+$#i', $asset->path(), $m) && isset($videos[$m[1]])) {
            $matches[$m[1]] = [null, false];
        }
        $names = array_unique(array_filter([$asset->get('title'), $asset->basename()]));
        foreach ($videos as $guid => $video) {
            foreach ($names as $name) {
                if (preg_match('/^'.preg_quote($name, '/').' \[([0-9a-f-]{36})\]$/i', $video['title'] ?? '', $m)) {
                    $matches[$guid] ??= [$m[1], false];
                    break;
                }
            }
        }

        return $matches;
    }

    // Paths and names can outlive the file they once identified. Compare actual bytes from
    // Bunny; a matching original or known MP4 rendition is required for weak matches.
    private function matchingSource(BunnyClient $bunny, $asset, array $video): ?string
    {
        $temp = tempnam(sys_get_temp_dir(), 'bunny-repair-');
        if ($temp === false) {
            throw new \RuntimeException('Unable to create a temporary file.');
        }
        try {
            foreach (['original', 'derivative'] as $source) {
                try {
                    if ($source === 'original') {
                        $bunny->downloadOriginal($video['guid'], $temp);
                    } else {
                        $bunny->download($video['guid'], $temp, $video);
                    }
                } catch (RequestException $e) {
                    if ($e->response->status() !== 404) {
                        throw $e;
                    }
                    continue;
                }

                $stream = $asset->stream();
                if (! is_resource($stream)) {
                    throw new \RuntimeException('Unable to read the current asset file.');
                }
                $hash = hash_init('sha256');
                try {
                    $size = hash_update_stream($hash, $stream);
                } finally {
                    fclose($stream);
                }
                clearstatcache(true, $temp);
                if ($size > 0 && $size === filesize($temp) && hash_final($hash) === hash_file('sha256', $temp)) {
                    return $source;
                }
            }

            return null;
        } finally {
            unlink($temp);
        }
    }
}
