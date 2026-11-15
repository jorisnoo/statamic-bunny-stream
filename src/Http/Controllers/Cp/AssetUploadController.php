<?php

namespace Noo\BunnyStream\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\BunnyClient;
use Noo\BunnyStream\Jobs\ImportVideo;
use Statamic\Assets\AssetUploader;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\AssetContainer;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Video uploads for asset containers: the browser sends the file straight to Bunny,
 * then a queued job copies it into the container.
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
        abort_unless($upload = Cache::pull('bunny-stream:upload:'.$guid), 404);
        $this->container($upload['container']);
        ImportVideo::dispatch($guid, $upload['container'], $upload['folder'], $upload['basename'], $upload['generation'])
            ->onQueue(config('statamic.bunny-stream.queue', 'default'));

        return response()->noContent();
    }

    private function container(string $handle)
    {
        $container = AssetContainer::find($handle);
        abort_unless($container && in_array($handle, config('statamic.bunny-stream.asset_containers', []), true), 404);
        $this->authorize('store', [AssetContract::class, $container]);

        return $container;
    }
}
