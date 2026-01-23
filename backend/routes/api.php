<?php

use App\Http\Controllers\Api\ThreadController;
use Illuminate\Support\Facades\Route;

Route::get('/threads', [ThreadController::class, 'index']);
Route::get('/threads/{sourceThreadId}', [ThreadController::class, 'show'])
    ->whereNumber('sourceThreadId');
Route::get('/threads/{sourceThreadId}/posts', [ThreadController::class, 'posts'])
    ->whereNumber('sourceThreadId');
