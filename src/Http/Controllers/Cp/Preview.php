<?php

namespace Noo\BunnyStream\Http\Controllers\Cp;

use Noo\BunnyStream\Data\BunnyVideo;
use Noo\BunnyStream\Repositories\VideoRepository;
use Statamic\Http\Controllers\CP\CpController;

class Preview extends CpController
{
    // Signed player details for the legacy Bunny field's preview.
    public function __invoke(string $guid, VideoRepository $videos)
    {
        $video = $videos->fetch($guid);
        abort_unless($video, 404);
        $stream = BunnyVideo::make($guid, null, $video['thumbnailFileName'] ?? 'thumbnail.jpg');

        return [
            'title' => $video['title'] ?? $guid,
            'embed_url' => $stream->embedUrl(),
            'duration' => $video['length'] ?? null,
            'width' => $video['width'] ?? null,
            'height' => $video['height'] ?? null,
        ];
    }
}
