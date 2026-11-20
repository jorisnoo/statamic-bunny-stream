<?php

namespace Noo\BunnyStream\Http\Controllers\Cp;

use Inertia\Inertia;
use Inertia\Response;
use Noo\BunnyStream\BunnyClient;
use Statamic\Http\Controllers\CP\CpController;

class Overview extends CpController
{
    public function __invoke(BunnyClient $bunny): Response
    {
        abort_unless(config('statamic.bunny-stream.show_dashboard', true), 404);

        return Inertia::render('BunnyOverview', [
            'title' => __('Media Browser'),
            'addon' => [
                'name' => 'Bunny Stream',
                'url' => 'https://github.com/jorisnoo/statamic-bunny-stream',
            ],
            'bunny' => [
                'configured' => $bunny->isConfigured(),
                'hostname' => config('statamic.bunny-stream.hostname'),
                'endpoint' => cp_route('bunny.cp.videos.index'),
                'chapters' => config('statamic.bunny-stream.chapters', false),
            ],
        ]);
    }
}
