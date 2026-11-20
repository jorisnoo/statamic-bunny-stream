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
        ], now()->addDay());

        return ['guid' => $guid, 'title' => $title, 'upload' => $bunny->uploadAuthorization($guid)];
    }

    public function complete(string $guid)
    {
        abort_unless($upload = Cache::get($key = 'bunny-stream:upload:'.$guid), 404);
        $container = $this->container($upload['container']);
        abort_if($upload['folder'] !== '' && ! $container->disk()->filesystem()->directoryExists($upload['folder']), 422, 'Unknown folder.');

        // Keep the extension when the import can store the original as it is; otherwise it gets Bunny's MP4.
        $extension = strtolower(pathinfo($upload['basename'], PATHINFO_EXTENSION));
        if (! in_array($extension, Streams::SOURCE_EXTENSIONS, true)) {
            $extension = 'mp4';
        }
        $path = Streams::availablePath($container->disk(), $upload['folder'], pathinfo($upload['basename'], PATHINFO_FILENAME), $extension);
        abort_unless($container->disk()->put($path, ''), 500, 'Unable to create the asset.');

        $asset = Asset::make()->container($container)->path($path);
        // Saved quietly: the saving listener would discard a stream record on a file without metadata.
        $asset->set('bunny_stream', [
            'key' => (string) Str::uuid(), 'generation' => $upload['generation'], 'library_id' => (string) config('statamic.bunny-stream.library_id'),
            'guid' => $guid, 'state' => 'remote_unready', 'placeholder' => true,
        ])->saveQuietly();
        AssetSaved::dispatch($asset);
        AssetCreated::dispatch($asset);
        Cache::forget($key);
        Streams::dispatch($asset);

        return ['asset' => $asset->id()];
    }

    private function container(string $handle)
    {
        $container = AssetContainer::find($handle);
        abort_unless($container && in_array($handle, config('statamic.bunny-stream.asset_containers', []), true), 404);
        $this->authorize('store', [AssetContract::class, $container]);

        return $container;
    }
}
