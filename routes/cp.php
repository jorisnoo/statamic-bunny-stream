<?php

use Illuminate\Support\Facades\Route;
use Laborb\BunnyStream\Http\Controllers\Cp\Overview;

Route::get('/bunny/videos', Overview::class)->name('bunny.cp.videoBrowser');
