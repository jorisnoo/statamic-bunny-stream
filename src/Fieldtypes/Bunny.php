<?php

namespace Noo\BunnyStream\Fieldtypes;

use Statamic\Fields\Fieldtype;

class Bunny extends Fieldtype
{
    protected static $title = 'Bunny';
    protected $icon = 'video';

    public function preload(): array
    {
        return [
            'api' => config('statamic.bunny-stream.api_key'),
            'library' => config('statamic.bunny-stream.library_id'),
            'hostname' => config('statamic.bunny-stream.hostname'),
        ];
    }

    public function augment($value): ?string
    {
        if (! $value) {
            return null;
        }

        $hostname = config('statamic.bunny-stream.hostname');

        return "https://{$hostname}/{$value}/playlist.m3u8";
    }
}
