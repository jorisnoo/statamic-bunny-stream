<?php

namespace Noo\BunnyStream\Migration;

use Illuminate\Validation\ValidationException;
use Statamic\Events;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;

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
            if (! $revision->isWorkingCopy()) { return; }
            $attributes = $revision->attributes();
            $item = str_starts_with($revision->key(), 'collections/')
                ? Entry::find($attributes['id']) : Term::find($attributes['id']);
            if ($item && str_starts_with($revision->key(), 'taxonomies/')) { $item = $item->in(explode('/', $revision->key())[2]); }
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
