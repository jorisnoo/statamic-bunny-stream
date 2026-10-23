<?php

use Illuminate\Support\Facades\Route;
use Noo\BunnyStream\Http\Controllers\Playlist;

Route::get('/bunny/{guid}/playlist.m3u8', Playlist::class)->whereUuid('guid')->name('bunny.playlist');
