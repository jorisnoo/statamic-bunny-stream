<?php

namespace Noo\BunnyStream;

use Statamic\Providers\AddonServiceProvider;
use Statamic\Facades\CP\Nav;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'bunny';

    protected $vite = [
        'input' => [
            'resources/js/addon.js',
            'resources/css/addon.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $routes = [
        'cp' => __DIR__ . '/../routes/cp.php',
    ];

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
        ], 'statamic-bunny-stream-config');
    }
}
