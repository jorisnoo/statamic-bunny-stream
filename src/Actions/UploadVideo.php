<?php

namespace Noo\BunnyStream\Actions;

use Noo\BunnyStream\BunnyClient;
use Statamic\Actions\Action;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Assets\AssetContainer;

/**
 * Adds "Upload Video" to the asset browser's container menu. The upload itself
 * runs in the browser, so the action only hands it the endpoint.
 */
class UploadVideo extends Action
{
    protected $icon = 'upload-cloud';

    protected $confirm = false;

    public static function title()
    {
        return __('Upload Video');
    }

    public function visibleTo($item)
    {
        return $item instanceof AssetContainer
            && in_array($item->handle(), config('statamic.bunny-stream.asset_containers', []), true)
            && app(BunnyClient::class)->isConfigured();
    }

    public function visibleToBulk($items)
    {
        return false;
    }

    public function authorize($user, $container)
    {
        return $user->can('store', [Asset::class, $container]);
    }

    public function run($items, $values)
    {
        return [
            'message' => false,
            'callback' => ['bunnyStreamUpload', [
                'container' => $items->first()->handle(),
                'endpoint' => cp_route('bunny.cp.assetUpload'),
            ]],
        ];
    }
}
