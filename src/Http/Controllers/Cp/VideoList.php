<?php

namespace Noo\BunnyStream\Http\Controllers\Cp;

use Noo\BunnyStream\Repositories\VideoRepository;
use Statamic\Http\Controllers\CP\CpController;

class VideoList extends CpController
{
    public function __invoke(VideoRepository $repo)
    {
        return response()->json($repo->fetchAll());
    }
}
