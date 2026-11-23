<?php

namespace Noo\BunnyStream\Http\Controllers\Cp;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Noo\BunnyStream\BunnyClient;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class Overview extends CpController
{
    public function __invoke(BunnyClient $bunny): Response|RedirectResponse
    {
        // With the legacy browser hidden, old bookmarks open the first video container the user can view.
        if (! config('statamic.bunny-stream.show_dashboard', true)) {
            foreach (config('statamic.bunny-stream.asset_containers', []) as $handle) {
                $container = AssetContainer::find($handle);
                if ($container && User::current()?->can('view', $container)) {
                    return redirect()->cpRoute('assets.browse.show', $handle);
                }
            }

            abort(404);
        }

        $this->authorize('manage bunny videos');

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
