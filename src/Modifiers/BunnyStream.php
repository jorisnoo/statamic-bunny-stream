<?php

namespace Noo\BunnyStream\Modifiers;

use Noo\BunnyStream\Data\BunnyVideo;
use Statamic\Modifiers\Modifier;

class BunnyStream extends Modifier
{
    protected static $handle = 'bunny_stream';

    // {{ stream = video | bunny_stream }} works for legacy Bunny fields and migrated assets alike.
    public function index($value)
    {
        return BunnyVideo::from($value);
    }
}
