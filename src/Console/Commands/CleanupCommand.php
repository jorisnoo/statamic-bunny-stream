<?php

namespace Noo\BunnyStream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupCommand extends Command
{
    protected $signature = 'bunny-stream:cleanup';

    protected $description = 'Remove old published assets and re-publish the latest build';

    public function handle(): void
    {
        $path = public_path('vendor/statamic-bunny-stream/build');

        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
            $this->info("Removed old assets from {$path}");
        } else {
            $this->comment('No published build directory found, skipping cleanup.');
        }

        $this->call('vendor:publish', [
            '--tag' => 'statamic-bunny-stream',
            '--force' => true,
        ]);

        $this->info('Assets re-published successfully.');
    }
}
