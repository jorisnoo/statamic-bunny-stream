<?php

namespace Noo\BunnyStream\Migration;

use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Statamic\Events;
use Statamic\Facades\Entry;

class RestoreSubscriber
{
    public function subscribe($events): void
    {
        app()->booted(fn () => app(Router::class)->pushMiddlewareToGroup('statamic.cp.authenticated', RevisionEditor::class));
        $events->listen(Events\EntryBlueprintFound::class, function ($e) {
            RevisionEditor::prepareEdit($e->entry, $e->blueprint);
        });
        foreach ([Events\EntrySaving::class => 'entry', Events\TermSaving::class => 'term',
            Events\GlobalVariablesSaving::class => 'variables', Events\UserSaving::class => 'user', Events\AssetSaving::class => 'asset'] as $event => $property) {
            $events->listen($event, function ($e) use ($property) {
                $item = $e->{$property};
                if ($property === 'entry') {
                    $data = $item->data()->all();
                    if ($data !== ($protected = RevisionEditor::protect($item, $data))) {
                        $item->data($protected);
                    }
                }
                $this->convert($item);
            });
        }
        $events->listen(Events\RevisionSaving::class, function ($e) {
            $revision = $e->revision;
            // Only entries are revisable in Statamic; other keys come from third-party repositories.
            if (! str_starts_with($revision->key(), 'collections/')) { return; }
            $id = $revision->attributes()['id'] ?? null;
            $routeEntry = request()->route('entry');
            $item = is_object($routeEntry) && method_exists($routeEntry, 'id') && $routeEntry->id() === $id
                ? $routeEntry
                : Entry::find($id);
            if (! $item) {
                if ($revision->isWorkingCopy()) {
                    throw ValidationException::withMessages(['bunny_stream' => 'Cannot resolve the item for this revision.']);
                }
                return;
            }
            try {
                $blueprint = RevisionBlueprint::resolve($item, $revision);
                $revision->attribute('blueprint', $blueprint->handle());
                if ($revision->isWorkingCopy()) {
                    $data = RevisionEditor::protect($item, $revision->attributes()['data']);
                    $copy = (clone $item)->data($data);
                    References::item($copy)->lenient()->convert($blueprint);
                    $revision->attribute('data', $copy->data()->all());
                }
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['bunny_stream' => $e->getMessage()]);
            }
        });
    }

    private function convert($item): void
    {
        try {
            if ($item instanceof \Statamic\Taxonomies\Term) {
                foreach ($item->localizations() as $localized) { References::item($localized)->lenient()->convert(); }
            } elseif ($item->blueprint()) { References::item($item)->lenient()->convert(); }
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['bunny_stream' => $e->getMessage()]);
        }
    }
}
