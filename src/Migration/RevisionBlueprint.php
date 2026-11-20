<?php

namespace Noo\BunnyStream\Migration;

use Statamic\Fields\Blueprint;

final class RevisionBlueprint
{
    public static function resolve($entry, $revision): Blueprint
    {
        $attributes = $revision->attributes();
        $handle = $attributes['blueprint'] ?? $attributes['data']['blueprint'] ?? null;
        if ($handle === null || $handle === '') {
            // Statamic did not always record a blueprint in revision attributes.
            return $entry->blueprint();
        }

        if (! is_string($handle) || ! ($blueprint = $entry->collection()->entryBlueprint($handle, $entry))) {
            $label = is_string($handle) ? $handle : get_debug_type($handle);
            throw new \RuntimeException('Cannot resolve revision blueprint '.$label.' at '.$revision->path());
        }

        return $blueprint;
    }
}
