<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrawlRun;
use App\Models\CrawlRunThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 抓取运行报表接口，负责提供运行汇总与主题明细查询。
 */
class CrawlRunController extends Controller
{
    /**
     * 获取运行记录列表（分页）。
     *
     * @param Request $request 请求对象
     * @return JsonResponse
     * 副作用：读库。
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $paginator = CrawlRun::query()
            ->orderByDesc('run_started_at')
            ->paginate($perPage, page: $page);

        $data = array_map(function (CrawlRun $run): array {
            return [
                'id' => (int) $run->id,
                'forum_id' => (int) $run->forum_id,
                'run_started_at' => $run->run_started_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'run_finished_at' => $run->run_finished_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'run_trigger_text' => (string) $run->run_trigger_text,
                'date_window_start' => $run->date_window_start?->format('Y-m-d'),
                'date_window_end' => $run->date_window_end?->format('Y-m-d'),
                'thread_scanned_count' => (int) $run->thread_scanned_count,
                'thread_change_detected_count' => (int) $run->thread_change_detected_count,
                'thread_updated_count' => (int) $run->thread_updated_count,
                'http_request_count' => (int) $run->http_request_count,
            ];
        }, $paginator->items());

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * 获取单次运行汇总详情。
     *
     * @param int $crawlRunId 运行记录 ID
     * @return JsonResponse
     * 副作用：读库。
     */
    public function show(int $crawlRunId): JsonResponse
    {
        $run = CrawlRun::query()->whereKey($crawlRunId)->first();
        if (!$run) {
            return response()->json(['message' => 'crawl_run_not_found'], 404);
        }

        $summary = CrawlRunThread::query()
            ->where('crawl_run_id', $run->id)
            ->selectRaw('COALESCE(SUM(new_post_count), 0) as new_post_count_total')
            ->selectRaw('COALESCE(SUM(updated_post_count), 0) as updated_post_count_total')
            ->selectRaw('SUM(CASE WHEN error_summary IS NOT NULL THEN 1 ELSE 0 END) as failed_thread_count')
            ->first();

        $durationMs = null;
        if ($run->run_started_at && $run->run_finished_at) {
            $durationMs = $run->run_finished_at->diffInMilliseconds($run->run_started_at, true);
        }

        return response()->json([
            'data' => [
                'id' => (int) $run->id,
                'forum_id' => (int) $run->forum_id,
                'run_started_at' => $run->run_started_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'run_finished_at' => $run->run_finished_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'run_trigger_text' => (string) $run->run_trigger_text,
                'date_window_start' => $run->date_window_start?->format('Y-m-d'),
                'date_window_end' => $run->date_window_end?->format('Y-m-d'),
                'thread_scanned_count' => (int) $run->thread_scanned_count,
                'thread_change_detected_count' => (int) $run->thread_change_detected_count,
                'thread_updated_count' => (int) $run->thread_updated_count,
                'http_request_count' => (int) $run->http_request_count,
                'new_post_count_total' => (int) ($summary->new_post_count_total ?? 0),
                'updated_post_count_total' => (int) ($summary->updated_post_count_total ?? 0),
                'failed_thread_count' => (int) ($summary->failed_thread_count ?? 0),
                'duration_ms' => $durationMs,
            ],
        ]);
    }

    /**
     * 获取指定运行的主题明细列表（分页）。
     *
     * 说明：only_failed=1 时仅返回失败明细。
     *
     * @param Request $request 请求对象
     * @param int $crawlRunId 运行记录 ID
     * @return JsonResponse
     * 副作用：读库。
     */
    public function threads(Request $request, int $crawlRunId): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'only_failed' => ['sometimes', 'boolean'],
            'thread_id' => ['sometimes', 'integer', 'min:1'],
            'source_thread_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $onlyFailed = $this->normalizeBoolean($validated['only_failed'] ?? null);
        $threadId = isset($validated['thread_id']) ? (int) $validated['thread_id'] : null;
        $sourceThreadId = isset($validated['source_thread_id']) ? (int) $validated['source_thread_id'] : null;

        $run = CrawlRun::query()->whereKey($crawlRunId)->first();
        if (!$run) {
            return response()->json(['message' => 'crawl_run_not_found'], 404);
        }

        $query = CrawlRunThread::query()
            ->where('crawl_run_id', $run->id)
            ->with('thread:id,source_thread_id')
            ->orderBy('started_at');

        if ($onlyFailed === true) {
            $query->whereNotNull('error_summary');
        }

        if ($threadId !== null) {
            $query->where('thread_id', $threadId);
        }

        if ($sourceThreadId !== null) {
            $query->whereHas('thread', function ($threadQuery) use ($sourceThreadId): void {
                $threadQuery->where('source_thread_id', $sourceThreadId);
            });
        }

        $paginator = $query->paginate($perPage, page: $page);

        $data = array_map(function (CrawlRunThread $runThread): array {
            return [
                'id' => (int) $runThread->id,
                'thread_id' => (int) $runThread->thread_id,
                'source_thread_id' => $runThread->thread?->source_thread_id === null ? null : (int) $runThread->thread->source_thread_id,
                'change_detected_by_last_reply_at' => (bool) $runThread->change_detected_by_last_reply_at,
                'detected_last_reply_at' => $runThread->detected_last_reply_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'fetched_page_count' => (int) $runThread->fetched_page_count,
                'page_limit_applied' => (bool) $runThread->page_limit_applied,
                'new_post_count' => (int) $runThread->new_post_count,
                'updated_post_count' => (int) $runThread->updated_post_count,
                'http_request_count' => (int) ($runThread->http_request_count ?? 0),
                'http_error_code' => $runThread->http_error_code === null ? null : (int) $runThread->http_error_code,
                'error_summary' => $runThread->error_summary,
                'started_at' => $runThread->started_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'finished_at' => $runThread->finished_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            ];
        }, $paginator->items());

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * 解析布尔类型参数，返回 null 表示未提供或无法解析。
     *
     * @param mixed $value 原始值
     * @return bool|null
     * 无副作用。
     */
    private function normalizeBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
