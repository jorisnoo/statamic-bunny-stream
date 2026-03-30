<?php

namespace Noo\BunnyStream\Fieldtypes;

use Noo\BunnyStream\Data\BunnyVideo;
use Noo\BunnyStream\Repositories\VideoRepository;
use Statamic\Fields\Fieldtype;

class Bunny extends Fieldtype
{
    protected static $title = 'Bunny';
    protected $icon = 'video';

    public function preload(): array
    {
        $thumbnailUrl = cp_route('bunny.cp.thumbnail', '__GUID__');

        $data = [
            'library'      => config('statamic.bunny-stream.library_id'),
            'hostname'     => config('statamic.bunny-stream.hostname'),
            'listUrl'      => cp_route('bunny.cp.videoList'),
            'thumbnailUrl' => $thumbnailUrl,
        ];

        if ($value = $this->field->value()) {
            $video = app(VideoRepository::class)->fetch($value);
            if ($video) {
                $data['initialTitle'] = $video['title'];
                $data['initialDate'] = $video['dateUploaded'];
                $data['initialThumbnail'] = str_replace('__GUID__', $value, $thumbnailUrl);
            }
        }

        return $data;
    }

    public function augment($value): ?BunnyVideo
    {
        if (! $value) {
            return null;
        }

        return new BunnyVideo(
            guid: $value,
            hostname: config('statamic.bunny-stream.hostname'),
            libraryId: config('statamic.bunny-stream.library_id'),
            tokenKey: config('statamic.bunny-stream.token_key'),
            tokenExpiry: config('statamic.bunny-stream.token_expiry', 24),
        );
    }
}
