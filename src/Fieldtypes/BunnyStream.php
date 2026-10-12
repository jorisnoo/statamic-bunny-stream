<?php

namespace Noo\BunnyStream\Fieldtypes;

use Noo\BunnyStream\Assets\Streams;
use Statamic\Contracts\Assets\Asset;
use Statamic\Fields\Fieldtype;

class BunnyStream extends Fieldtype
{
    protected static $title = 'Bunny Stream (asset)';
    protected $icon = 'video';

    public function preload(): array
    {
        $asset = $this->field->parent();
        if (! $asset instanceof Asset || ! Streams::enabled($asset)) {
            return [];
        }
        return ['endpoint' => cp_route('bunny.cp.asset'), 'asset' => $asset->id(),
            'chapters' => (bool) config('statamic.bunny-stream.chapters', false)];
    }

    public function process($value)
    {
        $asset = $this->field->parent();
        if (! $asset instanceof Asset) {
            return null;
        }
        // Read persisted data; this field is managed exclusively by the integration.
        return $asset->meta()['data']['bunny_stream'] ?? null;
    }

    public function augment($value)
    {
        return Streams::video(is_array($value) ? $value : null);
    }
}
