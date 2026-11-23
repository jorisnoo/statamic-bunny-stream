<?php

namespace Noo\BunnyStream\Assets;

use Statamic\Events;

class Subscriber
{
    public function subscribe($events): void
    {
        $events->listen(Events\AssetUploaded::class, fn ($e) => Streams::queueFromEvent($e->asset));
        $events->listen(Events\AssetReuploaded::class, fn ($e) => Streams::queueFromEvent($e->asset, true));
        $events->listen(Events\AssetReplaced::class, function ($e) {
            $new = Streams::fresh($e->newAsset->id());
            if (! $new || ! Streams::enabled($new)) {
                return;
            }
            $old = $e->originalAsset->get('bunny_stream');
            $data = $new->get('bunny_stream');
            $library = $data['library_id'] ?? (string) config('statamic.bunny-stream.library_id');
            // A surviving original still owns its GUID. Look it up rather than asking the
            // deleted Eloquent model, which can still report exists().
            if (! Streams::managed($old) || ($data !== null && ! Streams::managed($data))
                || $library !== ($old['library_id'] ?? null) || Streams::fresh($e->originalAsset->id())) {
                Streams::queueFromEvent($new);
                return;
            }

            $data ??= ['state' => 'not_synced', 'library_id' => $library];
            if (! Streams::video($data) && ($current = Streams::video($old))) {
                // The replacement may itself be a direct upload or an import still encoding.
                // Keep that GUID pending; it is not the one to show in the player.
                $data['pending_guid'] ??= $data['guid'] ?? null;
                $data['pending_metadata'] ??= $data['metadata'] ?? null;
                $data['guid'] = $current->guid();
                $data['guid_ready'] = true;
                $data['metadata'] = $old['metadata'] ?? [];
            }
            $data['legacy_guids'] = array_values(array_unique(array_filter(array_merge(
                $data['legacy_guids'] ?? [], $old['legacy_guids'] ?? [], [$old['guid'] ?? null]
            ))));
            $new->set('bunny_stream', $data);
            if ($data['state'] === 'not_synced' && empty($data['placeholder'])) {
                // Persist the inherited stream in the first queued state, not in a second save.
                Streams::queueFromEvent($new, true);
            } else {
                $new->saveQuietly();
            }
        });
        $events->listen(Events\AssetDeleting::class, function ($e) {
            $data = $e->asset->get('bunny_stream');
            if (Streams::managed($data) && isset($data['library_id']) && config('statamic.bunny-stream.delete_remote', false)) {
                Streams::requireQueue();
            }
        });
        $events->listen(Events\AssetDeleted::class, function ($e) {
            $data = $e->asset->get('bunny_stream');
            if (! Streams::managed($data) || ! isset($data['library_id'])) {
                return;
            }
            foreach (array_unique(array_filter([$data['guid'] ?? null, $data['pending_guid'] ?? null])) as $guid) {
                Streams::delete($guid, $data['library_id']);
            }
        });
        $events->listen(Events\AssetContainerBlueprintFound::class, function ($e) {
            if (in_array($e->container?->handle(), config('statamic.bunny-stream.asset_containers', []), true)) {
                $e->blueprint->ensureField('bunny_stream', [
                    'type' => 'bunny_stream', 'display' => __('Video stream'), 'listable' => false,
                ]);
            }
        });
        // CP form submissions must never overwrite metadata written by queue workers.
        $events->listen(Events\AssetSaving::class, function ($e) {
            $asset = $e->asset;
            if (! Streams::enabled($asset)) {
                return;
            }
            $stored = Streams::persisted($asset);
            $incoming = $asset->get('bunny_stream');
            if ($incoming === $stored) {
                return;
            }
            if (Streams::managed($stored) || (Streams::managed($incoming) && $stored !== null)) {
                $asset->set('bunny_stream', $stored);
            } elseif (Streams::managed($incoming)) {
                $asset->remove('bunny_stream');
            }
        });
    }
}
