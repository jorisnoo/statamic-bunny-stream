<?php

namespace Noo\BunnyStream;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\User;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'bunny';

    protected $actions = [
        Actions\UploadVideo::class,
    ];

    protected $commands = [
        Console\Commands\CleanupCommand::class,
        Console\Commands\BackupCommand::class,
        Console\Commands\ImportAssetsCommand::class,
        Console\Commands\MigrateFieldsCommand::class,
        Console\Commands\RollbackCommand::class,
        Console\Commands\RepairCommand::class,
        Console\Commands\PruneUploadsCommand::class,
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
        'web' => __DIR__ . '/../routes/web.php',
    ];

    protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule)
    {
        // Uploads abandoned in the browser leave empty videos in Bunny.
        if (config('statamic.bunny-stream.asset_containers')) {
            $schedule->command('bunny-stream:prune-uploads')->daily();
        }
    }

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
        // The legacy picker lists the whole library; limit it to people who can edit content.
        Gate::define('use bunny fieldtype', function ($user) {
            $user = User::fromUser($user);

            return $user && ($user->isSuper() || $user->hasPermission('manage bunny videos')
                || collect($user->permissions())->contains(fn ($permission) => str_starts_with($permission, 'edit ')
                    || (str_starts_with($permission, 'create ') && str_ends_with($permission, ' entries'))));
        });
    }

    public static function processingThumbnail(): string
    {
        $text = htmlspecialchars(__('Processing video…'), ENT_XML1);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 180"><rect width="320" height="180" fill="#3f3f46"/>'
            .'<text x="160" y="96" fill="#e4e4e7" font-family="system-ui, sans-serif" font-size="18" text-anchor="middle">'.$text.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public static function registerAssetHooks(): void
    {
        // Show Bunny's (custom) thumbnail for streamed videos in the asset browser,
        // Assets field tiles and the asset editor, without needing ffmpeg.
        $hook = function ($payload, $next) {
            $asset = $this->resource;
            if ($asset instanceof \Statamic\Contracts\Assets\Asset && Assets\Streams::enabled($asset)) {
                $data = $asset->get('bunny_stream');
                $data = is_array($data) ? $data : [];
                $video = Assets\Streams::video($data);
                if ($video) {
                    $payload->data->thumbnail = $video->thumbnail();
                } elseif (! empty($data['guid']) && empty($data['error'])) {
                    $payload->data->thumbnail = ServiceProvider::processingThumbnail();
                }
                // Lets the "Play video" field action find streams among an Assets field's selection.
                $payload->data->bunny_stream = [
                    'playable' => $video !== null,
                    'state' => $data['state'] ?? 'not_synced',
                    'endpoint' => cp_route('bunny.cp.asset'),
                ];
            }

            return $next($payload);
        };
        foreach ([\Statamic\Http\Resources\CP\Assets\Asset::class, \Statamic\Http\Resources\CP\Assets\FolderAsset::class,
            \Statamic\Http\Resources\CP\Assets\AssetsFieldtypeAsset::class] as $resource) {
            $resource::hook('asset', $hook);
        }
    }

    public static function provideUploadButton(): void
    {
        // Containers that get the asset browser's "Upload Video" button: those where the action is offered.
        Statamic::provideToScript(['bunnyStreamUpload' => function () {
            $user = User::current();
            $action = new Actions\UploadVideo;
            $containers = $user ? \Statamic\Facades\AssetContainer::all()
                ->filter(fn ($container) => $action->visibleTo($container) && $action->authorize($user, $container))
                ->map->handle()->values()->all() : [];

            return $containers ? ['containers' => $containers, 'endpoint' => cp_route('bunny.cp.assetUpload')] : null;
        }]);
    }

    public static function protectAssetMetadata(): void
    {
        // Only replace Statamic's own class; other asset drivers (e.g. Eloquent) keep theirs.
        if (! config('statamic.bunny-stream.asset_containers')
            || get_class(app(\Statamic\Contracts\Assets\Asset::class)) !== \Statamic\Assets\Asset::class) {
            return;
        }
        app()->bind(\Statamic\Contracts\Assets\Asset::class, Assets\SafeAsset::class);

        // Sites that restrict cached classes must still be able to unserialize these assets.
        if (is_array($classes = config('cache.serializable_classes'))) {
            config(['cache.serializable_classes' => array_merge($classes, [Assets\SafeAsset::class])]);
        }
    }

    public function bootAddon(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bunny-stream.php', 'statamic.bunny-stream');
        static::protectAssetMetadata();
        static::registerGates();
        static::registerAssetHooks();
        static::provideUploadButton();

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

        $this->loadJsonTranslationsFrom(__DIR__ . '/../lang');

        $this->publishes([
            __DIR__ . '/../config/bunny-stream.php' => config_path('statamic/bunny-stream.php'),
        ], 'bunny-stream-config');

        $this->bootPlaylistRoute();
    }

    /**
     * Statamic only registers addon web routes when its front-end routes are enabled,
     * but BunnyVideo links to the playlist route regardless.
     */
    protected function bootPlaylistRoute(): void
    {
        if (config('statamic.routes.enabled') || $this->app->routesAreCached()) {
            return;
        }

        Route::middleware(config('statamic.routes.middleware', 'web'))
            ->group(__DIR__ . '/../routes/web.php');
    }
}
