<?php

use App\Http\Controllers\ReviewLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/r/{token}', ReviewLinkController::class)->middleware('throttle:120,1');

Route::get('/', function () {
    return view('welcome');
});
