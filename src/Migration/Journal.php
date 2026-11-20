<?php

namespace Noo\BunnyStream\Migration;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

class Journal
{
    public static function directory(): string
    {
        return storage_path('app/bunny-stream');
    }

    public static function write(string $name, array $data): void
    {
        $directory = self::directory();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Cannot create migration directory.');
        }
        $path = $directory.'/'.basename($name).'.json';
        self::replace($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), 0600);
    }

    public static function read(string $name): array
    {
        $path = self::directory().'/'.basename($name).'.json';
        return is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public static function contents(array $file): string
    {
        $contents = isset($file['disk']) ? Storage::disk($file['disk'])->get($file['path']) : @file_get_contents($file['path']);
        if (! is_string($contents)) {
            throw new \RuntimeException('Cannot read '.$file['path']);
        }
        return $contents;
    }

    public static function change(array $file, string $after): array
    {
        return $file + ['before' => base64_encode(self::contents($file)), 'after' => base64_encode($after)];
    }

    public static function assertWritable(array $file): void
    {
        self::localPath($file);
    }

    private static function localPath(array $file): string
    {
        $path = $file['path'];
        if (! isset($file['disk'])) { return $path; }

        $disk = Storage::disk($file['disk']);
        if (! $disk instanceof FilesystemAdapter || get_class($disk->getAdapter()) !== LocalFilesystemAdapter::class
            || ($disk->getConfig()['read-only'] ?? false)) {
            throw new \RuntimeException('No safe atomic metadata write for '.$file['disk'].'::'.$path.'. Migrate these references through their storage backend before running this batch.');
        }

        return $disk->path($path);
    }

    private static function replace(string $path, string $bytes, int $permissions): void
    {
        $directory = realpath(dirname($path));
        if ($directory === false || ($temp = tempnam($directory, '.bunny-')) === false) {
            throw new \RuntimeException('Cannot stage atomic write for '.$path);
        }
        try {
            if (dirname($temp) !== $directory || ! chmod($temp, $permissions) || ! ($stream = fopen($temp, 'wb'))) {
                throw new \RuntimeException('Cannot stage atomic write for '.$path);
            }
            try {
                if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream) || ! fsync($stream)) {
                    throw new \RuntimeException('Unable to write '.$path);
                }
            } finally {
                fclose($stream);
            }
            if (! rename($temp, $path)) {
                throw new \RuntimeException('Unable to replace '.$path);
            }
        } finally {
            if (is_file($temp)) { unlink($temp); }
        }
    }

    public static function apply(string $run, bool $rollback = false): void
    {
        $journal = self::read($run);
        if (! isset($journal['files'])) {
            throw new \RuntimeException('Migration run not found.');
        }
        if (($journal['state'] ?? '') === 'rolled_back' && ! $rollback) {
            throw new \RuntimeException('This run was rolled back. Start a new migration.');
        }
        // Preflight the entire batch before touching any file, including on resume.
        foreach ($journal['files'] as $file) {
            self::assertWritable($file);
            $current = base64_encode(self::contents($file));
            if ($current !== $file['before'] && $current !== $file['after']) {
                throw new \RuntimeException('File changed since migration was prepared: '.$file['path']);
            }
        }
        $journal['state'] = $rollback ? 'rolling_back' : 'applying';
        self::write($run, $journal);
        $files = $rollback ? array_reverse($journal['files']) : $journal['files'];
        foreach ($files as $file) {
            $target = base64_decode($file[$rollback ? 'before' : 'after'], true);
            if (self::contents($file) === $target) { continue; }
            // Recheck immediately before each write to detect concurrent edits.
            $expected = base64_decode($file[$rollback ? 'after' : 'before'], true);
            if (self::contents($file) !== $expected) {
                throw new \RuntimeException('Concurrent edit: '.$file['path']);
            }
            $path = self::localPath($file);
            $path = realpath($path) ?: $path;
            $permissions = fileperms($path);
            if ($permissions === false || $target === false) {
                throw new \RuntimeException('Unable to write '.$path);
            }
            self::replace($path, $target, $permissions & 0777);
        }
        $journal['state'] = $rollback ? 'rolled_back' : 'complete';
        self::write($run, $journal);
    }
}
