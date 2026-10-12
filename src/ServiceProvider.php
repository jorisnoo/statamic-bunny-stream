<?php

namespace Noo\BunnyStream;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'bunny';

    protected $commands = [
        Console\Commands\CleanupCommand::class,
        Console\Commands\BackupCommand::class,
        Console\Commands\ImportAssetsCommand::class,
        Console\Commands\MigrateFieldsCommand::class,
        Console\Commands\RollbackCommand::class,
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

    public static function registerGates(): void
    {
        // The legacy picker lists the whole library; limit it to people who can edit something.
        Gate::define('use bunny fieldtype', function ($user) {
            $user = User::fromUser($user);

            return $user && ($user->isSuper() || $user->hasPermission('manage bunny videos')
                || collect($user->permissions())->contains(fn ($permission) => str_starts_with($permission, 'edit ')));
        });
    }

    public function bootAddon(): void
    {
        static::registerGates();

        Permission::extend(function () {
            Permission::group('bunny-stream', __('Bunny Stream'), function () {
                Permission::register('manage bunny videos')
                    ->label(__('Manage Bunny videos'));
            });
        });

        Nav::extend(function ($nav) {
            if (! config('statamic.bunny-stream.show_dashboard', true)) {
                return;
            }
            $nav->content(__('Media Browser'))
                ->section('Content')
                ->route('bunny.cp.videoBrowser')
                ->can('manage bunny videos')
                ->icon('movie-video-clip');
        });

        Fieldtypes\Bunny::register();
        Fieldtypes\BunnyStream::register();
        $this->app['events']->subscribe(Assets\Subscriber::class);
        $this->app['events']->subscribe(Migration\RestoreSubscriber::class);

        $this->mergeConfigFrom(__DIR__ . '/../config/bunny-stream.php', 'statamic.bunny-stream');
        $this->loadJsonTranslationsFrom(__DIR__ . '/../lang');

        $this->publishes([
            __DIR__ . '/../config/bunny-stream.php' => config_path('statamic/bunny-stream.php'),
        ], 'bunny-stream-config');
    }
}
