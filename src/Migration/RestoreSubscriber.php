<?php

namespace Noo\BunnyStream\Migration;

use Illuminate\Validation\ValidationException;
use Statamic\Events;
use Statamic\Facades\Entry;

class RestoreSubscriber
{
    public function subscribe($events): void
    {
        foreach ([Events\EntrySaving::class => 'entry', Events\TermSaving::class => 'term',
            Events\GlobalVariablesSaving::class => 'variables', Events\UserSaving::class => 'user', Events\AssetSaving::class => 'asset'] as $event => $property) {
            $events->listen($event, function ($e) use ($property) { $this->convert($e->{$property}); });
        }
        $events->listen(Events\RevisionSaving::class, function ($e) {
            $revision = $e->revision;
            // Only entries are revisable in Statamic; other keys come from third-party repositories.
            if (! $revision->isWorkingCopy() || ! str_starts_with($revision->key(), 'collections/')) { return; }
            $item = Entry::find($revision->attributes()['id']);
            if (! $item) { throw ValidationException::withMessages(['bunny_stream' => 'Cannot resolve the item for this revision.']); }
            $item = $item->makeFromRevision($revision);
            $this->convert($item);
            $revision->attribute('data', $item->data()->all());
        });
    }

    private function convert($item): void
    {
        try {
            if ($item instanceof \Statamic\Taxonomies\Term) {
                foreach ($item->localizations() as $localized) { References::item($localized)->convert(); }
            } elseif ($item->blueprint()) { References::item($item)->convert(); }
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['bunny_stream' => $e->getMessage()]);
        }
    }
}
