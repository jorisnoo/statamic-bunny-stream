<?php

namespace Noo\BunnyStream\Migration;

use Closure;
use Facades\Statamic\Fieldtypes\RowId;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\User;
use Statamic\Fields\Blueprint;
use Statamic\Support\Arr;

/** Keeps the Core entry editor on the working copy's schema and protects missing legacy values. */
final class RevisionEditor
{
    private const RECOVERY = '_bunny_stream_recovery';
    private const REMOVE = '_bunny_stream_remove_unresolved';
    private const EDIT_READY = '_bunny_stream_edit_ready';
    private const BLUEPRINT = '_bunny_stream_revision_blueprint';

    public function handle(Request $request, Closure $next)
    {
        if (! $request->routeIs('statamic.cp.collections.entries.*') || ! is_object($entry = $request->route('entry'))
            || ! method_exists($entry, 'workingCopy') || ! User::current()?->can('view', $entry)) {
            return $next($request);
        }

        $action = substr($request->route()->getName(), strlen('statamic.cp.collections.entries.'));
        $revision = match ($action) {
            'restore-revision' => $request->filled('revision') ? $entry->revision($request->input('revision')) : null,
            'revisions.show' => $request->route('revision'),
            'edit', 'update', 'published.store', 'published.destroy', 'preview.edit', 'preview.popout', 'revisions.store' =>
                $entry->revisionsEnabled() ? $entry->workingCopy() : null,
            default => null,
        };

        if ($revision) {
            try {
                $blueprint = RevisionBlueprint::resolve($entry, $revision);
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['bunny_stream' => $e->getMessage()]);
            }
            if ($entry->blueprint()->handle() !== $blueprint->handle()) {
                $entry = (clone $entry)->blueprint($blueprint->handle());
                $request->route()->setParameter('entry', $entry);
            }
            if (in_array($action, ['restore-revision', 'published.store', 'published.destroy'], true)) {
                $request->attributes->set(self::BLUEPRINT, [$entry->id(), $blueprint->handle()]);
            }
        }

        if ($action === 'update') {
            $copy = $revision ? $entry->makeFromRevision($revision) : clone $entry;
            $blueprint = $copy->blueprint();
            $references = References::item($copy)->lenient();
            $references->convert($blueprint);
            if ($unresolved = $references->unresolved()) {
                $request->attributes->set(self::RECOVERY, [$entry->id(), $copy->data()->all(), $unresolved, ! $blueprint->hasField(self::REMOVE)]);
            }
        }
        if ($action === 'edit') { $request->attributes->set(self::EDIT_READY, true); }

        return $next($request);
    }

    public static function prepareEdit($entry, Blueprint $blueprint): void
    {
        if (! request()->attributes->get(self::EDIT_READY) || ! $entry) { return; }

        $references = References::item($entry)->lenient();
        $references->convert($blueprint);
        if (! ($unresolved = $references->unresolved()) || $blueprint->hasField(self::REMOVE)) { return; }

        $fields = [];
        foreach ($unresolved as $path => $guid) { $fields[] = '`'.$path.'` (`'.$guid.'`)'; }
        $blueprint->ensureField(self::REMOVE, [
            'type' => 'toggle', 'display' => 'Remove unresolved Bunny videos',
            'instructions' => 'The original references are still stored: '.implode(', ', $fields).'. Choose a replacement asset, reimport the original and reopen this editor, or enable this switch to remove any references you leave empty.',
        ], 'sidebar');
    }

    public static function protect($entry, array $data): array
    {
        $request = request();
        if (($blueprint = $request->attributes->get(self::BLUEPRINT)) && $blueprint[0] === $entry->id()) {
            $data['blueprint'] = $blueprint[1];
        }
        $recovery = $request->attributes->get(self::RECOVERY);
        if (! $recovery || $recovery[0] !== $entry->id()) { return $data; }

        [, $before, $unresolved, $allowRemoval] = $recovery;
        if ($allowRemoval) { unset($data[self::REMOVE]); }
        if ($allowRemoval && $request->boolean(self::REMOVE)) { return $data; }

        $errors = [];
        foreach ($unresolved as $path => $guid) {
            $value = Arr::get($data, $path);
            if (! is_string($value) || $value === '' || ! self::sameRows($before, $data, $path)) {
                $errors[$path] = 'The unresolved Bunny video '.$guid.' is still stored. Select a replacement, or confirm removal before saving.';
            }
        }
        if ($errors) { throw ValidationException::withMessages($errors); }

        return $data;
    }

    private static function sameRows(array $before, array $after, string $path): bool
    {
        $rowId = RowId::handle();
        $segments = explode('.', $path);
        foreach ($segments as $index => $segment) {
            if (! ctype_digit($segment)) { continue; }
            $key = implode('.', array_slice($segments, 0, $index + 1));
            $old = Arr::get($before, $key);
            $new = Arr::get($after, $key);
            if (! is_array($old) || ! is_array($new)) { return false; }
            if ($id = $old[$rowId] ?? $old['attrs']['id'] ?? null) {
                if ($id !== ($new[$rowId] ?? $new['attrs']['id'] ?? null)) { return false; }
            } else {
                // Older rows may not have IDs. Only treat a change in the same row as a replacement
                // when the surrounding data still identifies that row.
                $child = $segments[$index + 1] ?? null;
                unset($old[$child], $new[$child], $new[$rowId]);
                if (! array_key_exists('enabled', $old) && ($new['enabled'] ?? false) === true) {
                    unset($new['enabled']);
                }
                if ($old !== $new) { return false; }
            }
        }
        return true;
    }
}
