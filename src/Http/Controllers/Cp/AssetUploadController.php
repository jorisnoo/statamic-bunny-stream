<?php

namespace Noo\BunnyStream\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\BunnyClient;
use Statamic\Assets\AssetUploader;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Events\AssetCreated;
use Statamic\Events\AssetSaved;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Video uploads for asset containers: the browser sends the file straight to Bunny,
 * the asset is created at once with an empty placeholder file, and a queued job
 * copies the source into it.
 */
class AssetUploadController extends CpController
{
    public function store(Request $request, BunnyClient $bunny)
    {
        $input = $request->validate([
            'container' => ['required', 'string'],
            'folder' => ['nullable', 'string'],
            'filename' => ['required', 'string', 'max:255'],
        ]);
        $container = $this->container($input['container']);
        $folder = trim($input['folder'] ?? '', '/');
        abort_if(in_array('..', explode('/', $folder), true) || ($folder !== '' && ! $container->disk()->filesystem()->directoryExists($folder)), 422, 'Unknown folder.');
        $basename = AssetUploader::getSafeFilename(basename(str_replace('\\', '/', $input['filename'])));
        abort_if(pathinfo($basename, PATHINFO_FILENAME) === '', 422, 'Invalid filename.');
        try {
            Streams::requireQueue();
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        // Same title convention as the sync job, so bunny-stream:repair can match the video.
        $generation = (string) Str::uuid();
        $title = $basename.' ['.$generation.']';
        $guid = $bunny->create($title)['guid'];
        Cache::put('bunny-stream:upload:'.$guid, [
            'container' => $container->handle(), 'folder' => $folder, 'basename' => $basename, 'generation' => $generation,
            'user' => $request->user()->getAuthIdentifier(),
        ], now()->addDay());

        return ['guid' => $guid, 'title' => $title, 'upload' => $bunny->uploadAuthorization($guid)];
    }

    public function complete(Request $request, string $guid)
    {
        // One completion at a time per upload; a retried request gets the asset created before.
        return Cache::lock('bunny-stream:complete:'.$guid, 120)->block(60, function () use ($request, $guid) {
            $upload = $this->upload($request, $guid);
            $container = $this->container($upload['container']);
            if (isset($upload['asset'])) {
                if (empty($upload['dispatched']) && $asset = Asset::find($upload['asset'])) {
                    Streams::dispatch($asset);
                    $this->remember($guid, ['dispatched' => true]);
                }

                return ['asset' => $upload['asset']];
            }
            abort_if($upload['folder'] !== '' && ! $container->disk()->filesystem()->directoryExists($upload['folder']), 422, 'Unknown folder.');

            // Keep the extension when the import can store the original as it is; otherwise it gets Bunny's MP4.
            $extension = strtolower(pathinfo($upload['basename'], PATHINFO_EXTENSION));
            $derivative = ! in_array($extension, Streams::SOURCE_EXTENSIONS, true);
            $asset = Streams::claimPath($container, $upload['folder'], pathinfo($upload['basename'], PATHINFO_FILENAME), $derivative ? 'mp4' : $extension,
                function ($path) use ($container, $upload, $guid, $derivative) {
                    abort_unless($container->disk()->put($path, ''), 500, 'Unable to create the asset.');
                    $asset = Asset::make()->container($container)->path($path);
                    // Saved quietly: the saving listener would discard a stream record on a file without metadata.
                    $asset->set('bunny_stream', array_filter([
                        'key' => (string) Str::uuid(), 'generation' => $upload['generation'], 'library_id' => (string) config('statamic.bunny-stream.library_id'),
                        'guid' => $guid, 'state' => 'remote_unready', 'placeholder' => true, 'derivative' => $derivative,
                    ]))->saveQuietly();

                    return $asset;
                });
            $this->remember($guid, ['asset' => $asset->id()]);
            Streams::dispatch($asset);
            $this->remember($guid, ['dispatched' => true]);
            AssetSaved::dispatch($asset);
            AssetCreated::dispatch($asset);

            return ['asset' => $asset->id()];
        });
    }

    // The browser gave up on an upload (discarded, or it failed): remove its empty Bunny video.
    public function cancel(Request $request, BunnyClient $bunny, string $guid)
    {
        return Cache::lock('bunny-stream:complete:'.$guid, 120)->block(60, function () use ($request, $bunny, $guid) {
            $upload = $this->upload($request, $guid);
            $this->container($upload['container']);
            abort_if(isset($upload['asset']), 409, 'The upload has already been added to the container.');
            try {
                $bunny->delete($guid);
            } catch (\Illuminate\Http\Client\RequestException $e) {
                abort_unless($e->response->status() === 404, 502, 'Bunny could not delete the video.');
            }
            Cache::forget('bunny-stream:upload:'.$guid);

            return response()->noContent();
        });
    }

    private function upload(Request $request, string $guid): array
    {
        abort_unless($upload = Cache::get('bunny-stream:upload:'.$guid), 404);
        abort_unless(($upload['user'] ?? null) === $request->user()->getAuthIdentifier(), 403);

        return $upload;
    }

    private function remember(string $guid, array $values): void
    {
        $key = 'bunny-stream:upload:'.$guid;
        Cache::put($key, array_merge(Cache::get($key, []), $values), now()->addDay());
    }

    private function container(string $handle)
    {
        $container = AssetContainer::find($handle);
        abort_unless($container && in_array($handle, config('statamic.bunny-stream.asset_containers', []), true), 404);
        $this->authorize('store', [AssetContract::class, $container]);

        return $container;
    }
}
