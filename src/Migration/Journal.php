<?php

namespace Noo\BunnyStream\Migration;

use Illuminate\Support\Facades\Storage;

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
        $temp = tempnam($directory, '.journal-');
        try {
            chmod($temp, 0600);
            if (file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false || ! rename($temp, $path)) {
                throw new \RuntimeException('Cannot persist migration journal.');
            }
        } finally {
            if (is_file($temp)) { unlink($temp); }
        }
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
            if (isset($file['disk'])) {
                if (! Storage::disk($file['disk'])->put($file['path'], $target)) {
                    throw new \RuntimeException('Unable to write '.$file['path']);
                }
            } else {
                $temp = tempnam(dirname($file['path']), '.bunny-');
                try {
                    chmod($temp, fileperms($file['path']) & 0777);
                    if (file_put_contents($temp, $target) === false || ! rename($temp, $file['path'])) {
                        throw new \RuntimeException('Unable to write '.$file['path']);
                    }
                } finally {
                    if (is_file($temp)) { unlink($temp); }
                }
            }
        }
        $journal['state'] = $rollback ? 'rolled_back' : 'complete';
        self::write($run, $journal);
    }
}
