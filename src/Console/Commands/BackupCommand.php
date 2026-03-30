<?php

namespace Noo\BunnyStream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupCommand extends Command
{
    protected $signature = 'bunny-stream:backup
        {--force : Re-download all videos, even if they already exist on disk}
        {--cleanup : Remove local files for videos that no longer exist in Bunny Stream}';

    protected $description = 'Download all Bunny Stream videos to the configured backup disk';

    public function handle(): int
    {
        $diskName = config('statamic.bunny-stream.backup_disk');

        if (! $diskName) {
            $this->error('No backup disk configured. Set BUNNY_STREAM_BACKUP_DISK in your .env file.');

            return self::FAILURE;
        }

        if (! config("filesystems.disks.{$diskName}")) {
            $this->error("Disk '{$diskName}' is not configured in filesystems.disks.");

            return self::FAILURE;
        }

        $storage = Storage::disk($diskName);
        $videos = $this->fetchAllVideos();

        if ($videos === null) {
            return self::FAILURE;
        }

        $readyVideos = array_filter($videos, fn (array $video) => ($video['status'] ?? 0) >= 4);

        $this->info(sprintf('Found %d videos (%d ready).', count($videos), count($readyVideos)));

        $downloaded = 0;
        $skipped = 0;
        $failed = 0;
        $force = $this->option('force');

        $bar = $this->output->createProgressBar(count($readyVideos));
        $bar->start();

        foreach ($readyVideos as $video) {
            $guid = $video['guid'];
            $path = "bunny-stream/{$guid}.mp4";

            if (! $force && $storage->exists($path)) {
                $skipped++;
                $bar->advance();

                continue;
            }

            try {
                $this->downloadVideo($storage, $video);
                $this->saveMetadata($storage, $video);
                $downloaded++;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("Failed to download '{$video['title']}' ({$guid}): {$e->getMessage()}");
                Log::warning("Bunny backup failed for {$guid}: {$e->getMessage()}");

                if ($storage->exists($path)) {
                    $storage->delete($path);
                }

                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $cleaned = 0;

        if ($this->option('cleanup')) {
            $cleaned = $this->cleanup($storage, $readyVideos);
        }

        $this->info(sprintf(
            'Backup complete: %d downloaded, %d skipped, %d failed%s.',
            $downloaded,
            $skipped,
            $failed,
            $this->option('cleanup') ? ", {$cleaned} cleaned up" : '',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function fetchAllVideos(): ?array
    {
        $libraryId = config('statamic.bunny-stream.library_id');
        $apiKey = config('statamic.bunny-stream.api_key');
        $videos = [];
        $page = 1;

        $this->info('Fetching video list from Bunny Stream...');

        do {
            try {
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'AccessKey' => $apiKey,
                ])->get("https://video.bunnycdn.com/library/{$libraryId}/videos", [
                    'page' => $page,
                    'itemsPerPage' => 100,
                    'orderBy' => 'date',
                ]);

                if (! $response->successful()) {
                    $this->error("Failed to fetch video list (page {$page}): HTTP {$response->status()}");

                    return null;
                }
            } catch (\Throwable $e) {
                $this->error("Failed to fetch video list (page {$page}): {$e->getMessage()}");

                return null;
            }

            $data = $response->json();
            $videos = array_merge($videos, $data['items'] ?? []);
            $totalItems = $data['totalItems'] ?? 0;
            $page++;
        } while (count($videos) < $totalItems);

        return $videos;
    }

    private function downloadVideo($storage, array $video): void
    {
        $libraryId = config('statamic.bunny-stream.library_id');
        $apiKey = config('statamic.bunny-stream.api_key');
        $guid = $video['guid'];
        $url = "https://video.bunnycdn.com/library/{$libraryId}/videos/{$guid}/download";

        $tempPath = tempnam(sys_get_temp_dir(), 'bunny_backup_');

        try {
            $response = Http::timeout(0)
                ->connectTimeout(30)
                ->withHeaders(['AccessKey' => $apiKey])
                ->withOptions(['sink' => $tempPath])
                ->get($url);

            if (! $response->successful()) {
                throw new \RuntimeException("Download failed with HTTP {$response->status()}");
            }

            $stream = fopen($tempPath, 'r');
            $storage->writeStream("bunny-stream/{$guid}.mp4", $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            @unlink($tempPath);
        }
    }

    private function saveMetadata($storage, array $video): void
    {
        $metadata = [
            'guid' => $video['guid'],
            'title' => $video['title'] ?? null,
            'dateUploaded' => $video['dateUploaded'] ?? null,
            'storageSize' => $video['storageSize'] ?? null,
            'length' => $video['length'] ?? null,
            'width' => $video['width'] ?? null,
            'height' => $video['height'] ?? null,
            'status' => $video['status'] ?? null,
            'thumbnailFileName' => $video['thumbnailFileName'] ?? null,
        ];

        $storage->put(
            "bunny-stream/{$video['guid']}.json",
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function cleanup($storage, array $videos): int
    {
        $remoteGuids = array_map(fn (array $video) => $video['guid'], $videos);
        $files = $storage->files('bunny-stream');
        $cleaned = 0;

        foreach ($files as $file) {
            if (! str_ends_with($file, '.mp4')) {
                continue;
            }

            $guid = pathinfo($file, PATHINFO_FILENAME);

            if (in_array($guid, $remoteGuids)) {
                continue;
            }

            $storage->delete($file);
            $storage->delete("bunny-stream/{$guid}.json");
            $cleaned++;

            $this->line("Removed orphaned backup: {$guid}");
        }

        return $cleaned;
    }
}
