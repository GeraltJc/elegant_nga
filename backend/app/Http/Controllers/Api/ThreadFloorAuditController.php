<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrawlRun;
use App\Models\ThreadFloorAuditPost;
use App\Models\ThreadFloorAuditRun;
use App\Models\ThreadFloorAuditThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 缺楼层审计接口，负责提供审计运行、主题明细与楼层明细查询。
 */
class ThreadFloorAuditController extends Controller
{
    /**
     * 规则：主题修补状态候选值。
     */
    private const THREAD_STATUS_OPTIONS = ['missing', 'repaired', 'partial', 'failed', 'skipped'];

    /**
     * 规则：楼层修补状态候选值。
     */
    private const POST_STATUS_OPTIONS = ['missing', 'ignored', 'repaired', 'still_missing', 'failed'];

    /**
     * 获取缺楼层审计运行列表（分页）。
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

        $paginator = ThreadFloorAuditRun::query()
            ->orderByDesc('run_started_at')
            ->paginate($perPage, page: $page);

        $data = array_map(function (ThreadFloorAuditRun $run): array {
            return $this->formatRunSummary($run);
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
     * 获取缺楼层审计运行详情。
     *
     * @param int $auditRunId 审计运行 ID
     * @return JsonResponse
     * 副作用：读库。
     */
    public function show(int $auditRunId): JsonResponse
    {
        $run = ThreadFloorAuditRun::query()->whereKey($auditRunId)->first();
        if (!$run) {
            return response()->json(['message' => 'audit_run_not_found'], 404);
        }

        return response()->json([
            'data' => $this->formatRunDetail($run),
        ]);
    }

    /**
     * 获取指定审计运行的主题明细列表（分页）。
     *
     * 说明：only_failed=1 时仅返回修补失败明细；repair_status 优先级更高。
     *
     * @param Request $request 请求对象
     * @param int $auditRunId 审计运行 ID
     * @return JsonResponse
     * 副作用：读库。
     */
    public function threads(Request $request, int $auditRunId): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'only_failed' => ['sometimes', 'boolean'],
            'repair_status' => ['sometimes', 'string', 'in:'.implode(',', self::THREAD_STATUS_OPTIONS)],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $onlyFailed = $this->normalizeBoolean($validated['only_failed'] ?? null);
        $repairStatus = $validated['repair_status'] ?? null;

        $run = ThreadFloorAuditRun::query()->whereKey($auditRunId)->first();
        if (!$run) {
            return response()->json(['message' => 'audit_run_not_found'], 404);
        }

        $query = ThreadFloorAuditThread::query()
            ->where('audit_run_id', $run->id)
            ->orderBy('id');

        if ($repairStatus !== null) {
            $query->where('repair_status', $repairStatus);
        } elseif ($onlyFailed === true) {
            $query->where('repair_status', 'failed');
        }

        $paginator = $query->paginate($perPage, page: $page);

        $data = array_map(function (ThreadFloorAuditThread $auditThread): array {
            return $this->formatAuditThread($auditThread);
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
     * 获取单个审计主题详情。
     *
     * @param int $auditThreadId 审计主题明细 ID
     * @return JsonResponse
     * 副作用：读库。
     */
    public function showThread(int $auditThreadId): JsonResponse
    {
        $auditThread = ThreadFloorAuditThread::query()->whereKey($auditThreadId)->first();
        if (!$auditThread) {
            return response()->json(['message' => 'audit_thread_not_found'], 404);
        }

        return response()->json([
            'data' => $this->formatAuditThread($auditThread),
        ]);
    }

    /**
     * 获取指定审计主题的楼层明细列表（分页）。
     *
     * @param Request $request 请求对象
     * @param int $auditThreadId 审计主题明细 ID
     * @return JsonResponse
     * 副作用：读库。
     */
    public function posts(Request $request, int $auditThreadId): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'repair_status' => ['sometimes', 'string', 'in:'.implode(',', self::POST_STATUS_OPTIONS)],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $repairStatus = $validated['repair_status'] ?? null;

        $auditThread = ThreadFloorAuditThread::query()->whereKey($auditThreadId)->first();
        if (!$auditThread) {
            return response()->json(['message' => 'audit_thread_not_found'], 404);
        }

        $query = ThreadFloorAuditPost::query()
            ->where('audit_thread_id', $auditThread->id)
            ->orderBy('floor_number');

        if ($repairStatus !== null) {
            $query->where('repair_status', $repairStatus);
        }

        $paginator = $query->paginate($perPage, page: $page);

        $data = array_map(function (ThreadFloorAuditPost $auditPost): array {
            return [
                'id' => (int) $auditPost->id,
                'floor_number' => (int) $auditPost->floor_number,
                'repair_status' => (string) $auditPost->repair_status,
                'attempt_count_before' => (int) $auditPost->attempt_count_before,
                'attempt_count_after' => $auditPost->attempt_count_after === null
                    ? null
                    : (int) $auditPost->attempt_count_after,
                'repair_error_category' => $auditPost->repair_error_category,
                'repair_http_error_code' => $auditPost->repair_http_error_code === null
                    ? null
                    : (int) $auditPost->repair_http_error_code,
                'repair_error_summary' => $auditPost->repair_error_summary,
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
     * 规范化审计运行摘要返回结构。
     *
     * @param ThreadFloorAuditRun $run 审计运行模型
     * @return array<string, mixed>
     * 无副作用。
     */
    private function formatRunSummary(ThreadFloorAuditRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'run_started_at' => $run->run_started_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'run_finished_at' => $run->run_finished_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'run_trigger_text' => (string) $run->run_trigger_text,
            'repair_enabled' => (bool) $run->repair_enabled,
            'total_thread_count' => (int) $run->total_thread_count,
            'missing_thread_count' => (int) $run->missing_thread_count,
            'repaired_thread_count' => (int) $run->repaired_thread_count,
            'partial_thread_count' => (int) $run->partial_thread_count,
            'failed_thread_count' => (int) $run->failed_thread_count,
            'failed_http_count' => (int) $run->failed_http_count,
            'failed_parse_count' => (int) $run->failed_parse_count,
            'failed_db_count' => (int) $run->failed_db_count,
            'failed_unknown_count' => (int) $run->failed_unknown_count,
        ];
    }

    /**
     * 规范化审计运行详情返回结构（包含耗时）。
     *
     * @param ThreadFloorAuditRun $run 审计运行模型
     * @return array<string, mixed>
     * 无副作用。
     */
    private function formatRunDetail(ThreadFloorAuditRun $run): array
    {
        $durationMs = null;
        if ($run->run_started_at && $run->run_finished_at) {
            $durationMs = $run->run_finished_at->diffInMilliseconds($run->run_started_at, true);
        }

        $crawlRunId = CrawlRun::query()
            ->where('audit_run_id', $run->id)
            ->orderBy('id')
            ->value('id');

        $data = $this->formatRunSummary($run);
        $data['duration_ms'] = $durationMs;
        $data['crawl_run_id'] = $crawlRunId === null ? null : (int) $crawlRunId;

        return $data;
    }

    /**
     * 规范化主题审计明细返回结构。
     *
     * @param ThreadFloorAuditThread $auditThread 审计主题明细模型
     * @return array<string, mixed>
     * 无副作用。
     */
    private function formatAuditThread(ThreadFloorAuditThread $auditThread): array
    {
        return [
            'id' => (int) $auditThread->id,
            'audit_run_id' => (int) $auditThread->audit_run_id,
            'thread_id' => (int) $auditThread->thread_id,
            'source_thread_id' => (int) $auditThread->source_thread_id,
            'max_floor_number' => (int) $auditThread->max_floor_number,
            'post_count' => (int) $auditThread->post_count,
            'missing_floor_count' => (int) $auditThread->missing_floor_count,
            'ignored_floor_count' => (int) $auditThread->ignored_floor_count,
            'repair_status' => (string) $auditThread->repair_status,
            'repair_crawl_run_id' => $auditThread->repair_crawl_run_id === null
                ? null
                : (int) $auditThread->repair_crawl_run_id,
            'repair_attempted_at' => $auditThread->repair_attempted_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'repair_finished_at' => $auditThread->repair_finished_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'repair_after_max_floor_number' => $auditThread->repair_after_max_floor_number === null
                ? null
                : (int) $auditThread->repair_after_max_floor_number,
            'repair_after_post_count' => $auditThread->repair_after_post_count === null
                ? null
                : (int) $auditThread->repair_after_post_count,
            'repair_remaining_floor_count' => $auditThread->repair_remaining_floor_count === null
                ? null
                : (int) $auditThread->repair_remaining_floor_count,
            'repair_error_category' => $auditThread->repair_error_category,
            'repair_http_error_code' => $auditThread->repair_http_error_code === null
                ? null
                : (int) $auditThread->repair_http_error_code,
            'repair_error_summary' => $auditThread->repair_error_summary,
        ];
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
