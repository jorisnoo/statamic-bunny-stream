<?php

use Illuminate\Support\Facades\Route;
use Noo\BunnyStream\Http\Controllers\Cp\Overview;

Route::get('/bunny/videos', Overview::class)->name('bunny.cp.videoBrowser');
Route::get('/bunny/videos/list', \Noo\BunnyStream\Http\Controllers\Cp\VideoList::class)->name('bunny.cp.videoList');
Route::get('/bunny/thumbnail/{guid}', \Noo\BunnyStream\Http\Controllers\Cp\Thumbnail::class)->name('bunny.cp.thumbnail');
Route::post('/bunny/thumbnail/{guid}/bust', \Noo\BunnyStream\Http\Controllers\Cp\BustThumbnailCache::class)->name('bunny.cp.thumbnailBust');
