<?php

namespace Noo\BunnyStream\Http\Controllers\Cp;

use Illuminate\Support\Facades\Cache;
use Statamic\Http\Controllers\CP\CpController;

class BustThumbnailCache extends CpController
{
    public function __invoke(string $guid)
    {
        Cache::forget('bunny:thumb:' . $guid);
        Cache::forget('bunny:' . $guid);
        Cache::forget('bunny:all');

        return response()->noContent();
    }
}
