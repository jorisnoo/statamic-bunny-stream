<?php

namespace Noo\BunnyStream\Assets;

use Statamic\Events;

class Subscriber
{
    public function subscribe($events): void
    {
        $events->listen(Events\AssetUploaded::class, fn ($e) => Streams::queue($e->asset));
        $events->listen(Events\AssetReuploaded::class, fn ($e) => Streams::queue($e->asset, true));
        $events->listen(Events\AssetReplaced::class, function ($e) {
            Streams::queue($e->newAsset);
            $old = $e->originalAsset->get('bunny_stream', []);
            $data = $e->newAsset->get('bunny_stream', []);
            if (! $e->originalAsset->exists() && ! empty($old['guid']) && ! empty($data)
                && empty($data['guid']) && $old['library_id'] === $data['library_id']) {
                $e->newAsset->set('bunny_stream', array_merge($data, ['guid' => $old['guid'], 'metadata' => $old['metadata'] ?? []]))->saveQuietly();
            }
            if (! $e->originalAsset->exists() && isset($old['library_id'], $data['library_id']) && $old['library_id'] === $data['library_id']) {
                $latest = $e->newAsset->get('bunny_stream');
                $latest['legacy_guids'] = array_values(array_unique(array_filter(array_merge($latest['legacy_guids'] ?? [], $old['legacy_guids'] ?? [], [$old['guid'] ?? null]))));
                $e->newAsset->set('bunny_stream', $latest)->saveQuietly();
            }
        });
        $events->listen(Events\AssetDeleting::class, function ($e) {
            if ($e->asset->get('bunny_stream') && config('statamic.bunny-stream.delete_remote', false)) { Streams::requireQueue(); }
        });
        $events->listen(Events\AssetDeleted::class, function ($e) {
            $data = $e->asset->get('bunny_stream', []);
            foreach (array_unique(array_filter([$data['guid'] ?? null, $data['pending_guid'] ?? null])) as $guid) {
                Streams::delete($guid, $data['library_id']);
            }
        });
        $events->listen(Events\AssetContainerBlueprintFound::class, function ($e) {
            if (in_array($e->container?->handle(), config('statamic.bunny-stream.asset_containers', []), true)) {
                $e->blueprint->ensureField('bunny_stream', [
                    'type' => 'bunny_stream', 'display' => 'Bunny Stream', 'listable' => false,
                ]);
            }
        });
        // CP form submissions must never overwrite metadata written by queue workers.
        $events->listen(Events\AssetSaving::class, function ($e) {
            if ($e->asset->exists()) {
                $contents = $e->asset->disk()->get($e->asset->metaPath());
                $meta = $contents ? \Statamic\Facades\YAML::parse($contents) : [];
                $e->asset->set('bunny_stream', $meta['data']['bunny_stream'] ?? null);
            }
        });
    }
}
