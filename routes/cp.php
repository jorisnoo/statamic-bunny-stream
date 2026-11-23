<?php

use Illuminate\Support\Facades\Route;
use Noo\BunnyStream\Http\Controllers\Cp\Preview;
use Noo\BunnyStream\Http\Controllers\Cp\Thumbnail;
use Noo\BunnyStream\Http\Controllers\Cp\VideoList;

// Fieldtype support, available to CP users who can edit content.
Route::middleware('can:use bunny fieldtype')->group(function () {
    Route::get('/bunny/videos/list', VideoList::class)->name('bunny.cp.videoList');
    Route::get('/bunny/thumbnail/{guid}', Thumbnail::class)->whereUuid('guid')->name('bunny.cp.thumbnail');
    Route::get('/bunny/preview/{guid}', Preview::class)->whereUuid('guid')->name('bunny.cp.preview');
});

Route::match(['get', 'post'], '/bunny/asset-stream', \Noo\BunnyStream\Http\Controllers\Cp\AssetStreamController::class)->name('bunny.cp.asset');

// Large uploads into asset containers, gated by each container's own upload permission.
Route::post('/bunny/asset-upload', [\Noo\BunnyStream\Http\Controllers\Cp\AssetUploadController::class, 'store'])->name('bunny.cp.assetUpload');
Route::post('/bunny/asset-upload/{guid}', [\Noo\BunnyStream\Http\Controllers\Cp\AssetUploadController::class, 'complete'])->whereUuid('guid');
Route::delete('/bunny/asset-upload/{guid}', [\Noo\BunnyStream\Http\Controllers\Cp\AssetUploadController::class, 'cancel'])->whereUuid('guid');
