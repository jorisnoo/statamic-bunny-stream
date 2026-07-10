<?php

use Illuminate\Support\Facades\Route;
use Noo\BunnyStream\Http\Controllers\Cp\Overview;
use Noo\BunnyStream\Http\Controllers\Cp\Thumbnail;
use Noo\BunnyStream\Http\Controllers\Cp\VideoList;
use Noo\BunnyStream\Http\Controllers\Cp\VideosController;

// Fieldtype support, available to any CP user who can edit entries.
Route::get('/bunny/videos/list', VideoList::class)->name('bunny.cp.videoList');
Route::get('/bunny/thumbnail/{guid}', Thumbnail::class)->whereUuid('guid')->name('bunny.cp.thumbnail');

// Media browser, gated behind its own permission.
Route::middleware('can:manage bunny videos')->name('bunny.cp.')->group(function () {
    Route::get('/bunny/videos', Overview::class)->name('videoBrowser');

    Route::name('videos.')->prefix('/bunny/api/videos')->whereUuid('guid')->group(function () {
        Route::get('/', [VideosController::class, 'index'])->name('index');
        Route::post('/', [VideosController::class, 'store'])->name('store');
        Route::get('/{guid}', [VideosController::class, 'show'])->name('show');
        Route::patch('/{guid}', [VideosController::class, 'update'])->name('update');
        Route::delete('/{guid}', [VideosController::class, 'destroy'])->name('destroy');
        Route::post('/{guid}/thumbnail', [VideosController::class, 'thumbnail'])->name('thumbnail');
        Route::post('/{guid}/transcribe', [VideosController::class, 'transcribe'])->name('transcribe');
    });
});
