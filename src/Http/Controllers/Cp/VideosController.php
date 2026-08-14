<?php

namespace Noo\BunnyStream\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Noo\BunnyStream\BunnyClient;
use Noo\BunnyStream\Repositories\VideoRepository;
use Statamic\Http\Controllers\CP\CpController;

class VideosController extends CpController
{
    public function index(Request $request, BunnyClient $bunny)
    {
        return $bunny->videos(
            page: max(1, (int) $request->query('page', 1)),
            perPage: min(100, max(1, (int) $request->query('perPage', 10))),
            search: $request->query('search'),
        );
    }

    public function show(BunnyClient $bunny, string $guid)
    {
        return $bunny->video($guid);
    }

    public function store(Request $request, BunnyClient $bunny, VideoRepository $videos)
    {
        $data = $request->validate([
            'title' => ['required', 'string'],
            'thumbnailTime' => ['nullable', 'integer', 'min:0'],
        ]);

        $video = $bunny->create($data['title'], $data['thumbnailTime'] ?? 0);

        $videos->forget();

        return [
            'guid' => $video['guid'],
            'upload' => $bunny->uploadAuthorization($video['guid']),
        ];
    }

    public function update(Request $request, BunnyClient $bunny, VideoRepository $videos, string $guid)
    {
        $rules = [
            'title' => ['sometimes', 'string'],
            'chapters' => ['prohibited'],
        ];

        if (config('statamic.bunny-stream.chapters', false)) {
            $rules['chapters'] = ['sometimes', 'array'];
            $rules['chapters.*.title'] = ['nullable', 'string'];
            $rules['chapters.*.start'] = ['required', 'integer', 'min:0'];
            $rules['chapters.*.end'] = ['required', 'integer', 'min:0'];
        }

        $data = $request->validate($rules);

        $bunny->update($guid, $data);

        $videos->forget($guid);

        return response()->noContent();
    }

    public function destroy(BunnyClient $bunny, VideoRepository $videos, string $guid)
    {
        $bunny->delete($guid);

        $videos->forget($guid);

        return response()->noContent();
    }

    public function thumbnail(Request $request, BunnyClient $bunny, VideoRepository $videos, string $guid)
    {
        $request->validate([
            'thumbnail' => ['required', 'image', 'mimes:jpeg,png', 'max:10240'],
        ]);

        $file = $request->file('thumbnail');

        $bunny->setThumbnail($guid, $file->get(), $file->getMimeType());

        $videos->forget($guid);

        return response()->noContent();
    }

    public function transcribe(BunnyClient $bunny, string $guid)
    {
        abort_unless(config('statamic.bunny-stream.chapters', false), 404);

        $bunny->transcribe($guid);

        return response()->noContent();
    }
}
