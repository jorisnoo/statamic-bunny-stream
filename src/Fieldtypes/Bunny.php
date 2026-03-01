<?php

namespace Noo\BunnyStream\Fieldtypes;

use Noo\BunnyStream\Repositories\VideoRepository;
use Statamic\Fields\Fieldtype;

class Bunny extends Fieldtype
{
    protected static $title = 'Bunny';
    protected $icon = 'video';

    public function preload(): array
    {
        $data = [
            'library'  => config('statamic.bunny-stream.library_id'),
            'hostname' => config('statamic.bunny-stream.hostname'),
            'listUrl'  => cp_route('bunny.cp.videoList'),
        ];

        if ($value = $this->field->value()) {
            $video = app(VideoRepository::class)->fetch($value);
            if ($video) {
                $data['initialTitle'] = $video['title'];
                $data['initialDate'] = $video['dateUploaded'];
            }
        }

        return $data;
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
