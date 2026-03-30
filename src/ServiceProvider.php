<?php

namespace Noo\BunnyStream;

use Illuminate\Support\Facades\File;
use Statamic\Facades\CP\Nav;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'bunny';

    protected $commands = [
        Console\Commands\CleanupCommand::class,
        Console\Commands\BackupCommand::class,
    ];

    protected $vite = [
        'input' => [
            'resources/js/bunny-stream.js',
            'resources/css/bunny-stream.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $routes = [
        'cp' => __DIR__ . '/../routes/cp.php',
    ];

    protected function bootPublishAfterInstall(): self
    {
        Statamic::afterInstalled(function ($command) {
            $buildDir = public_path('vendor/statamic-bunny-stream/build');

            if (File::isDirectory($buildDir)) {
                File::deleteDirectory($buildDir);
            }

            $command->call('vendor:publish', [
                '--tag' => $this->getAddon()->slug(),
                '--force' => true,
            ]);
        });

        return $this;
    }

    public function bootAddon(): void
    {
        Nav::extend(function ($nav) {
            $nav->content(__('Media Browser'))
                ->section('Content')
                ->route('bunny.cp.videoBrowser')
                ->icon('movie-video-clip');
        });

        Fieldtypes\Bunny::register();

        $this->mergeConfigFrom(__DIR__ . '/../config/bunny-stream.php', 'statamic.bunny-stream');
        $this->loadJsonTranslationsFrom(__DIR__ . '/../lang');

        $this->publishes([
            __DIR__ . '/../config/bunny-stream.php' => config_path('statamic/bunny-stream.php'),
        ], 'bunny-stream-config');
    }
}
