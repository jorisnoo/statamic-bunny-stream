<?php

namespace Noo\BunnyStream\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\BunnyClient;
use Statamic\Facades\Asset;
use Statamic\Http\Controllers\CP\CpController;

class AssetStreamController extends CpController
{
    public function __invoke(Request $request, BunnyClient $bunny)
    {
        $input = $request->validate(['asset' => ['required', 'string'], 'action' => ['sometimes', 'in:retry,thumbnail,chapters,transcribe,attach']]);
        $asset = Asset::find($input['asset']);
        abort_unless($asset && Streams::enabled($asset), 404);
        $this->authorize($request->isMethod('get') ? 'view' : 'edit', $asset);
        $data = $asset->get('bunny_stream', []);
        abort_if(isset($data['library_id']) && $data['library_id'] !== (string) config('statamic.bunny-stream.library_id'), 409, 'Bunny library configuration changed.');
        $guid = $data['guid'] ?? null;
        if ($request->isMethod('post')) {
            switch ($request->input('action')) {
                case 'retry':
                    empty($data) ? Streams::queue($asset) : Streams::dispatch($asset);
                    break;
                case 'attach':
                    $values = $request->validate(['guid' => ['required', 'uuid']]);
                    abort_unless(($data['state'] ?? '') === 'creating', 409);
                    $video = $bunny->video($values['guid']);
                    abort_unless(str_contains($video['title'] ?? '', '['.$data['generation'].']'), 422, 'Video does not match this upload generation.');
                    $data = array_merge($data, ['pending_guid' => $values['guid'], 'state' => 'uploading', 'error' => null]);
                    $asset->set('bunny_stream', $data)->saveQuietly();
                    Streams::dispatch($asset);
                    break;
                case 'thumbnail':
                    abort_unless($guid, 409);
                    $request->validate(['thumbnail' => ['required', 'image', 'mimes:jpeg,png', 'max:10240']]);
                    $file = $request->file('thumbnail');
                    $bunny->setThumbnail($guid, $file->get(), $file->getMimeType());
                    break;
                case 'chapters':
                    abort_unless($guid && config('statamic.bunny-stream.chapters', false), 409);
                    $values = $request->validate(['chapters' => ['required', 'array'], 'chapters.*.title' => ['nullable', 'string'],
                        'chapters.*.start' => ['required', 'integer', 'min:0'], 'chapters.*.end' => ['required', 'integer', 'gte:chapters.*.start']]);
                    $bunny->update($guid, $values);
                    break;
                case 'transcribe':
                    abort_unless($guid && config('statamic.bunny-stream.chapters', false), 409);
                    $bunny->transcribe($guid);
                    break;
                default:
                    abort(422, 'An action is required.');
            }
        }
        $data = $asset->get('bunny_stream', []);

        // Fetch on explicit editor refresh, keeping ordinary asset saves independent of Bunny.
        $refresh = $request->boolean('refresh') || in_array($request->input('action'), ['thumbnail', 'chapters'], true);
        $metadata = $guid && $refresh ? $bunny->video($guid) : ($data['metadata'] ?? []);
        if ($guid && $refresh && $request->isMethod('post')) {
            $latest = Asset::find($asset->id());
            $current = $latest?->get('bunny_stream', []);
            if (($current['guid'] ?? null) === $guid) {
                $latest->set('bunny_stream', array_merge($current, ['metadata' => $metadata]))->saveQuietly();
            }
        }
        $video = Streams::video(array_merge($data, ['metadata' => $metadata]));
        return ['state' => $data['state'] ?? 'not_synced', 'error' => $data['error'] ?? null,
            'source' => $data['source'] ?? null, 'embed_url' => $video?->embedUrl(), 'thumbnail' => $video?->thumbnail(),
            'chapters' => $metadata['chapters'] ?? [], 'can_edit' => $request->user()->can('edit', $asset)];
    }
}
