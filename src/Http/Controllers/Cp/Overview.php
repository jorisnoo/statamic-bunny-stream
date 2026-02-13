<?php

namespace Laborb\BunnyStream\Http\Controllers\Cp;

use Illuminate\Contracts\View\View;
use Statamic\Http\Controllers\CP\CpController;

class Overview extends CpController
{
    public function __invoke(): View
    {
        return view('bunny::cp.overview', [
            'title' => __('Video Browser'),
            'addon' => [
                'name' => 'Bunny Stream',
                'url' => 'https://github.com/laborb/statamic-bunny-stream',
            ],
            'bunny' => [
                'apiKey' => config('statamic.bunny-stream.api_key'),
                'hostname' => config('statamic.bunny-stream.hostname'),
                'library' => config('statamic.bunny-stream.library_id'),
            ],
        ]);
    }
}
