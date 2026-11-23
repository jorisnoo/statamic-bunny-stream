<?php

namespace Noo\BunnyStream\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Noo\BunnyStream\Assets\Streams;
use Noo\BunnyStream\BunnyClient;
use Statamic\Facades\Asset;
use Statamic\Http\Controllers\CP\CpController;

class AssetStreamController extends CpController
{
    private const VIDEO_ACTIONS = ['thumbnail', 'chapters', 'transcribe'];

    public function __invoke(Request $request, BunnyClient $bunny)
    {
        $input = $request->validate(['asset' => ['required', 'string'], 'action' => ['sometimes', 'in:retry,thumbnail,chapters,transcribe,attach']]);
        $asset = Asset::find($input['asset']);
        abort_unless($asset && Streams::enabled($asset), 404);
        $this->authorize($request->isMethod('get') ? 'view' : 'edit', $asset);
        $data = self::streamData($asset);
        abort_if(isset($data['library_id']) && $data['library_id'] !== (string) config('statamic.bunny-stream.library_id'), 409, 'The streaming library configuration changed.');
        $guid = Streams::video($data)?->guid();
        $action = $request->isMethod('post') ? ($input['action'] ?? null) : null;
        $metadata = null;
        if ($request->isMethod('post')) {
            if (in_array($action, self::VIDEO_ACTIONS, true)) {
                $selected = $request->validate(['guid' => ['required', 'uuid']])['guid'];
                abort_unless($selected === $guid, 409, 'The video changed. Refresh the editor.');
            }
            switch ($action) {
                case 'retry':
                    try {
                        $failedDirectEncode = ! empty($data['direct_upload']) && empty($data['placeholder'])
                            && ($data['state'] ?? null) === 'remote_unready'
                            && in_array((int) ($data['pending_metadata']['status'] ?? -1), [5, 6], true);
                        if (($data['state'] ?? 'not_synced') === 'not_synced' || $failedDirectEncode) {
                            Streams::queue($asset, true);
                        } else {
                            // Clear the previous failure so the editor keeps polling the new attempt.
                            $asset->set('bunny_stream', array_merge($data, ['error' => null]))->saveQuietly();
                            Streams::dispatch($asset);
                        }
                    } catch (\RuntimeException $e) {
                        abort(409, $e->getMessage());
                    }
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
                    $request->validate(['thumbnail' => ['required', 'image', 'mimes:jpeg,png', 'max:10240']]);
                    $file = $request->file('thumbnail');
                    $bunny->setThumbnail($guid, $file->get(), $file->getMimeType());
                    break;
                case 'chapters':
                    abort_unless(config('statamic.bunny-stream.chapters', false), 409);
                    $values = $request->validate(['chapters' => ['present', 'array'], 'chapters.*.title' => ['nullable', 'string'],
                        'chapters.*.start' => ['required', 'integer', 'min:0'], 'chapters.*.end' => ['required', 'integer', 'gte:chapters.*.start']]);
                    $bunny->update($guid, $values);
                    break;
                case 'transcribe':
                    abort_unless(config('statamic.bunny-stream.chapters', false), 409);
                    // Capture the pre-generation chapters for Bunny libraries without feature status.
                    $metadata = $bunny->video($guid);
                    $bunny->transcribe($guid);
                    break;
                default:
                    abort(422, 'An action is required.');
            }
        }
        $data = self::streamData($asset);

        // Status and signed URL renewals use cached metadata, except when explicitly checking Bunny.
        $refresh = $request->boolean('refresh')
            || ($request->boolean('refresh_chapters') && ($data['chapter_generation_guid'] ?? null) === $guid)
            || in_array($action, self::VIDEO_ACTIONS, true);
        if ($guid && $refresh) {
            $metadata ??= $bunny->video($guid);

            // The worker may have published a replacement during the Bunny request. Use the
            // current record for both the write and the response, even if the fetch is obsolete.
            $asset = Streams::fresh($asset->id());
            abort_unless($asset && Streams::enabled($asset), 404);
            $data = self::streamData($asset);
            abort_if(isset($data['library_id']) && $data['library_id'] !== (string) config('statamic.bunny-stream.library_id'), 409, 'The streaming library configuration changed.');
            if (Streams::video($data)?->guid() === $guid && ($metadata['guid'] ?? $guid) === $guid) {
                $updated = array_merge($data, ['metadata' => $metadata]);
                if ($action === 'transcribe') {
                    $updated['chapter_generation_guid'] = $guid;
                } elseif (($data['chapter_generation_guid'] ?? null) === $guid) {
                    $status = $metadata['smartGenerateFeaturesStatus']['chapters'] ?? null;
                    // Finished/Failed are authoritative. Older libraries may expose only the chapters.
                    if (in_array($status, [3, 4], true) || (($status === null || $status === 0) && $action !== 'chapters'
                        && ($metadata['chapters'] ?? []) !== ($data['metadata']['chapters'] ?? []))) {
                        unset($updated['chapter_generation_guid']);
                    }
                }
                if ($updated !== $data) {
                    $asset->set('bunny_stream', $updated)->saveQuietly();
                    $data = $updated;
                }
            }
        }

        $video = Streams::video($data);
        $metadata = $data['metadata'] ?? [];
        $generating = $video && ($data['chapter_generation_guid'] ?? null) === $video->guid();
        $pending = $data['pending_metadata'] ?? [];
        return ['state' => $data['state'] ?? 'not_synced', 'error' => $data['error'] ?? null,
            'source' => $data['source'] ?? null, 'guid' => $video?->guid(), 'embed_url' => $video?->embedUrl(), 'thumbnail' => $video?->thumbnail(),
            'title' => $asset->get('title') ?: $asset->basename(),
            'duration' => $metadata['length'] ?? null, 'width' => $metadata['width'] ?? null, 'height' => $metadata['height'] ?? null,
            // Encoding progress of the upload in flight (a replacement keeps the current stream playable).
            'progress' => isset($pending['encodeProgress']) ? (int) $pending['encodeProgress'] : null,
            'chapters' => $metadata['chapters'] ?? [], 'generating_chapters' => $generating,
            'chapter_generation_failed' => $video && ! $generating && ($metadata['smartGenerateFeaturesStatus']['chapters'] ?? null) === 4,
            'can_edit' => $request->user()->can('edit', $asset)];
    }

    private static function streamData($asset): array
    {
        $data = $asset->get('bunny_stream');

        return Streams::managed($data) ? $data : [];
    }
}
