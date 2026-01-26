<?php

use App\Http\Controllers\Api\PostRevisionController;
use App\Http\Controllers\Api\CrawlRunController;
use App\Http\Controllers\Api\ThreadController;
use Illuminate\Support\Facades\Route;

Route::get('/threads', [ThreadController::class, 'index']);
Route::get('/threads/{sourceThreadId}', [ThreadController::class, 'show'])
    ->whereNumber('sourceThreadId');
Route::get('/threads/{sourceThreadId}/posts', [ThreadController::class, 'posts'])
    ->whereNumber('sourceThreadId');
Route::get('/posts/{postId}/revisions', [PostRevisionController::class, 'index'])
    ->whereNumber('postId');
Route::get('/crawl-runs', [CrawlRunController::class, 'index']);
Route::get('/crawl-runs/{crawlRunId}', [CrawlRunController::class, 'show'])
    ->whereNumber('crawlRunId');
Route::get('/crawl-runs/{crawlRunId}/threads', [CrawlRunController::class, 'threads'])
    ->whereNumber('crawlRunId');
