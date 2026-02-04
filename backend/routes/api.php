<?php

use App\Http\Controllers\Api\CrawlRunController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostRevisionController;
use App\Http\Controllers\Api\ThreadController;
use App\Http\Controllers\Api\ThreadFloorAuditController;
use Illuminate\Support\Facades\Route;

Route::get('/threads', [ThreadController::class, 'index']);
Route::get('/threads/{sourceThreadId}', [ThreadController::class, 'show'])
    ->whereNumber('sourceThreadId');
Route::get('/threads/{sourceThreadId}/posts', [ThreadController::class, 'posts'])
    ->whereNumber('sourceThreadId');
Route::get('/threads/{sourceThreadId}/posts/quote', [PostController::class, 'quote'])
    ->whereNumber('sourceThreadId');
Route::get('/posts/{postId}/revisions', [PostRevisionController::class, 'index'])
    ->whereNumber('postId');
Route::get('/crawl-runs', [CrawlRunController::class, 'index']);
Route::get('/crawl-runs/{crawlRunId}', [CrawlRunController::class, 'show'])
    ->whereNumber('crawlRunId');
Route::get('/crawl-runs/{crawlRunId}/threads', [CrawlRunController::class, 'threads'])
    ->whereNumber('crawlRunId');
Route::get('/floor-audit-runs', [ThreadFloorAuditController::class, 'index']);
Route::get('/floor-audit-runs/{auditRunId}', [ThreadFloorAuditController::class, 'show'])
    ->whereNumber('auditRunId');
Route::get('/floor-audit-runs/{auditRunId}/threads', [ThreadFloorAuditController::class, 'threads'])
    ->whereNumber('auditRunId');
Route::get('/floor-audit-threads/{auditThreadId}', [ThreadFloorAuditController::class, 'showThread'])
    ->whereNumber('auditThreadId');
Route::get('/floor-audit-threads/{auditThreadId}/posts', [ThreadFloorAuditController::class, 'posts'])
    ->whereNumber('auditThreadId');
