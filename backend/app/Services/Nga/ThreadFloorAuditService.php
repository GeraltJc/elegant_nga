<?php

namespace App\Services\Nga;

use App\Models\CrawlRunThread;
use App\Models\Post;
use App\Models\Thread;
use App\Models\ThreadFloorAuditRun;
use App\Models\ThreadFloorAuditThread;
use App\Models\ThreadFloorAuditPost;
use App\Models\ThreadFloorRepairAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 缺楼层审计与修补服务，用于离线扫描缺口并记录修补结果。
 */
class ThreadFloorAuditService
{
    /**
     * 规则：缺口页码估算的默认每页楼层数。
     *
     * 说明：用于“只补缺口”场景定位目标页码；不追求完全精准，预算内尽力补齐。
     */
    private const DEFAULT_PAGE_SIZE_ESTIMATE = 20;

    /**
     * 规则：修补状态 - 发现缺口但未修补。
     */
    private const STATUS_MISSING = 'missing';

    /**
     * 规则：修补状态 - 修补完成且缺口消失。
     */
    private const STATUS_REPAIRED = 'repaired';

    /**
     * 规则：修补状态 - 修补后仍有缺口。
     */
    private const STATUS_PARTIAL = 'partial';

    /**
     * 规则：修补状态 - 修补过程中失败。
     */
    private const STATUS_FAILED = 'failed';

    /**
     * 规则：修补状态 - 因超次数限制跳过修补。
     */
    private const STATUS_SKIPPED = 'skipped';

    /**
     * 规则：楼层审计状态 - 发现缺口但未修补。
     */
    private const POST_STATUS_MISSING = 'missing';

    /**
     * 规则：楼层审计状态 - 因超次数限制跳过修补。
     */
    private const POST_STATUS_IGNORED = 'ignored';

    /**
     * 规则：楼层审计状态 - 修补成功。
     */
    private const POST_STATUS_REPAIRED = 'repaired';

    /**
     * 规则：楼层审计状态 - 修补后仍缺失。
     */
    private const POST_STATUS_STILL_MISSING = 'still_missing';

    /**
     * 规则：楼层审计状态 - 修补失败。
     */
    private const POST_STATUS_FAILED = 'failed';

    /**
     * 规则：修补失败归因 - HTTP 相关。
     */
    private const ERROR_CATEGORY_HTTP = 'http';

    /**
     * 规则：修补失败归因 - 解析相关。
     */
    private const ERROR_CATEGORY_PARSE = 'parse';

    /**
     * 规则：修补失败归因 - 数据库写入相关。
     */
    private const ERROR_CATEGORY_DB = 'db';

    /**
     * 规则：修补失败归因 - 未知。
     */
    private const ERROR_CATEGORY_UNKNOWN = 'unknown';

    /**
     * 规则：单楼层最大修补尝试次数。
     */
    private const MAX_REPAIR_ATTEMPTS_PER_FLOOR = 3;

    public function __construct(private readonly NgaLiteCrawler $crawler)
    {
    }

    /**
     * 执行缺楼层审计，并按需触发修补。
     *
     * @param bool $repairEnabled 是否执行修补
     * @param int $maxPostPages 修补时单次抓取最大页数
     * @param int|null $limit 限制审计主题数量（null 表示不限制）
     * @param array<int, int>|null $sourceThreadIds 指定 tid 列表（null 表示不限制）
     * @param string $triggerText 触发来源
     * @return ThreadFloorAuditRun 审计运行记录
     * 副作用：写入审计表与可能触发抓取修补。
     */
    public function run(
        bool $repairEnabled,
        int $maxPostPages,
        ?int $limit,
        string $triggerText,
        ?array $sourceThreadIds = null
    ): ThreadFloorAuditRun {
        $now = CarbonImmutable::now('Asia/Shanghai');
        $totalThreadCount = $this->countAuditableThreads($sourceThreadIds);

        $run = ThreadFloorAuditRun::create([
            'run_started_at' => $now,
            'run_trigger_text' => $triggerText,
            'repair_enabled' => $repairEnabled,
            'total_thread_count' => $totalThreadCount,
            'missing_thread_count' => 0,
            'repaired_thread_count' => 0,
            'partial_thread_count' => 0,
            'failed_thread_count' => 0,
            'failed_http_count' => 0,
            'failed_parse_count' => 0,
            'failed_db_count' => 0,
            'failed_unknown_count' => 0,
        ]);

        $missingThreads = $this->findMissingCandidates($limit, $sourceThreadIds);
        $missingThreadCount = 0;
        $repairedThreadCount = 0;
        $partialThreadCount = 0;
        $failedThreadCount = 0;
        $failedHttpCount = 0;
        $failedParseCount = 0;
        $failedDbCount = 0;
        $failedUnknownCount = 0;

        foreach ($missingThreads as $candidate) {
            $missingFloors = $this->resolveMissingFloors(
                (int) $candidate->thread_id,
                (int) $candidate->max_floor_number
            );

            if ($missingFloors === []) {
                continue;
            }

            $missingThreadCount++;

            $filtered = $this->splitMissingFloorsByAttemptLimit(
                (int) $candidate->thread_id,
                $missingFloors,
                self::MAX_REPAIR_ATTEMPTS_PER_FLOOR
            );

            $auditThread = ThreadFloorAuditThread::create([
                'audit_run_id' => $run->id,
                'thread_id' => (int) $candidate->thread_id,
                'source_thread_id' => (int) $candidate->source_thread_id,
                'max_floor_number' => (int) $candidate->max_floor_number,
                'post_count' => (int) $candidate->post_count,
                'missing_floor_count' => count($missingFloors),
                'ignored_floor_count' => count($filtered['ignored']),
                'repair_status' => self::STATUS_MISSING,
            ]);

            $this->createAuditPosts(
                $run->id,
                $auditThread,
                $missingFloors,
                $filtered
            );

            if (!$repairEnabled) {
                continue;
            }

            if ($filtered['pending'] === []) {
                $auditThread->fill([
                    'repair_status' => self::STATUS_SKIPPED,
                    'repair_finished_at' => CarbonImmutable::now('Asia/Shanghai'),
                ]);
                $auditThread->save();
                continue;
            }

            $repairResult = $this->repairAuditThread(
                $auditThread,
                $filtered['pending'],
                $filtered['attempt_counts'],
                $maxPostPages
            );
            if ($repairResult['status'] === self::STATUS_REPAIRED) {
                $repairedThreadCount++;
            } elseif ($repairResult['status'] === self::STATUS_PARTIAL) {
                $partialThreadCount++;
            } elseif ($repairResult['status'] === self::STATUS_FAILED) {
                $failedThreadCount++;
                $category = $repairResult['error_category'] ?? self::ERROR_CATEGORY_UNKNOWN;
                if ($category === self::ERROR_CATEGORY_HTTP) {
                    $failedHttpCount++;
                } elseif ($category === self::ERROR_CATEGORY_PARSE) {
                    $failedParseCount++;
                } elseif ($category === self::ERROR_CATEGORY_DB) {
                    $failedDbCount++;
                } else {
                    $failedUnknownCount++;
                }
            }
        }

        $run->fill([
            'run_finished_at' => CarbonImmutable::now('Asia/Shanghai'),
            'missing_thread_count' => $missingThreadCount,
            'repaired_thread_count' => $repairedThreadCount,
            'partial_thread_count' => $partialThreadCount,
            'failed_thread_count' => $failedThreadCount,
            'failed_http_count' => $failedHttpCount,
            'failed_parse_count' => $failedParseCount,
            'failed_db_count' => $failedDbCount,
            'failed_unknown_count' => $failedUnknownCount,
        ]);
        $run->save();

        return $run;
    }

    /**
     * 统计满足审计条件的主题数量。
     *
     * @param array<int, int>|null $sourceThreadIds 指定 tid 列表（null 表示不限制）
     * @return int 主题数量
     * 无副作用。
     */
    private function countAuditableThreads(?array $sourceThreadIds): int
    {
        $query = Thread::query()
            ->where('is_truncated_by_page_limit', false)
            ->where('is_skipped_by_page_total_limit', false)
            ->whereNotNull('crawl_cursor_max_floor_number');

        if ($sourceThreadIds !== null && $sourceThreadIds !== []) {
            $query->whereIn('source_thread_id', $sourceThreadIds);
        }

        return $query->count();
    }

    /**
     * 查询疑似缺楼层的主题候选集合。
     *
     * @param int|null $limit 限制数量（null 表示不限制）
     * @param array<int, int>|null $sourceThreadIds 指定 tid 列表（null 表示不限制）
     * @return Collection 候选主题集合
     * 无副作用。
     */
    private function findMissingCandidates(?int $limit, ?array $sourceThreadIds): Collection
    {
        $query = Thread::query()
            ->select([
                'threads.id as thread_id',
                'threads.source_thread_id',
                'threads.crawl_cursor_max_floor_number as max_floor_number',
                DB::raw('COUNT(posts.id) as post_count'),
            ])
            ->leftJoin('posts', 'posts.thread_id', '=', 'threads.id')
            ->where('threads.is_truncated_by_page_limit', false)
            ->where('threads.is_skipped_by_page_total_limit', false)
            ->whereNotNull('threads.crawl_cursor_max_floor_number')
            ->groupBy('threads.id', 'threads.source_thread_id', 'threads.crawl_cursor_max_floor_number')
            // 业务规则：最大楼层号 + 1 与实际楼层数不一致即视为缺口
            ->havingRaw('COUNT(posts.id) < threads.crawl_cursor_max_floor_number + 1')
            ->orderBy('threads.id');

        if ($sourceThreadIds !== null && $sourceThreadIds !== []) {
            $query->whereIn('threads.source_thread_id', $sourceThreadIds);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * 计算指定主题的缺失楼层列表。
     *
     * @param int $threadId 主题 ID
     * @param int $maxFloor 最大楼层号（0基）
     * @return array<int, int> 缺失楼层号数组
     * 无副作用。
     */
    private function resolveMissingFloors(int $threadId, int $maxFloor): array
    {
        if ($maxFloor < 0) {
            return [];
        }

        $floors = Post::query()
            ->where('thread_id', $threadId)
            ->pluck('floor_number')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $floorMap = array_fill_keys($floors, true);
        $missing = [];

        for ($floor = 0; $floor <= $maxFloor; $floor++) {
            if (!isset($floorMap[$floor])) {
                $missing[] = $floor;
            }
        }

        return $missing;
    }

    /**
     * 修补缺楼层主题并更新审计明细。
     *
     * @param ThreadFloorAuditThread $auditThread 审计明细
     * @param array<int, int> $pendingFloors 需要尝试修补的缺口楼层号
     * @param array<int, int> $attemptCounts 缺口楼层尝试次数映射
     * @param int $maxPostPages 修补时单次抓取最大页数
     * @return array{status:string, error_category:string|null} 修补结果
     * 副作用：触发抓取并更新审计明细。
     */
    private function repairAuditThread(
        ThreadFloorAuditThread $auditThread,
        array $pendingFloors,
        array $attemptCounts,
        int $maxPostPages
    ): array
    {
        $auditThread->fill([
            'repair_attempted_at' => CarbonImmutable::now('Asia/Shanghai'),
            'repair_error_summary' => null,
            'repair_error_category' => null,
            'repair_http_error_code' => null,
        ]);
        $auditThread->save();

        $this->recordRepairAttempts(
            (int) $auditThread->thread_id,
            (int) $auditThread->source_thread_id,
            $pendingFloors
        );

        $this->markAuditPostsAttempted(
            $auditThread->id,
            $pendingFloors,
            $attemptCounts
        );

        $pagesToFetch = $this->estimatePagesToFetch(
            $pendingFloors,
            $maxPostPages,
            self::DEFAULT_PAGE_SIZE_ESTIMATE
        );

        $result = $this->crawler->repairThreadMissingFloorsByPages(
            (int) $auditThread->source_thread_id,
            $pagesToFetch,
            (int) $auditThread->max_floor_number,
            'floor_audit'
        );

        $repairRunId = $result['run_id'] ?? null;
        $auditThread->repair_crawl_run_id = $repairRunId === null ? null : (int) $repairRunId;

        if (($result['failed_thread_count'] ?? 0) > 0) {
            $errorContext = $this->resolveRepairErrorContext($repairRunId, (int) $auditThread->thread_id);
            $afterSnapshot = $this->buildRepairAfterSnapshot((int) $auditThread->thread_id);
            $auditThread->fill([
                'repair_status' => self::STATUS_FAILED,
                'repair_error_summary' => $errorContext['summary'],
                'repair_error_category' => $errorContext['category'],
                'repair_http_error_code' => $errorContext['http_error_code'],
                'repair_finished_at' => CarbonImmutable::now('Asia/Shanghai'),
                'repair_after_max_floor_number' => $afterSnapshot['max_floor_number'],
                'repair_after_post_count' => $afterSnapshot['post_count'],
                'repair_remaining_floor_count' => $afterSnapshot['remaining_floor_count'],
            ]);
            $auditThread->save();

            $this->markAuditPostsFailed($auditThread->id, $pendingFloors, $errorContext);

            return [
                'status' => self::STATUS_FAILED,
                'error_category' => $errorContext['category'],
            ];
        }

        $afterSnapshot = $this->buildRepairAfterSnapshot((int) $auditThread->thread_id);
        $remaining = $afterSnapshot['remaining_floors'];
        $repairStatus = $remaining === [] ? self::STATUS_REPAIRED : self::STATUS_PARTIAL;

        $auditThread->fill([
            'repair_status' => $repairStatus,
            'repair_finished_at' => CarbonImmutable::now('Asia/Shanghai'),
            'repair_after_max_floor_number' => $afterSnapshot['max_floor_number'],
            'repair_after_post_count' => $afterSnapshot['post_count'],
            'repair_remaining_floor_count' => $afterSnapshot['remaining_floor_count'],
        ]);
        $auditThread->save();

        $this->markAuditPostsAfterRepair($auditThread->id, $pendingFloors, $remaining);

        return [
            'status' => $repairStatus,
            'error_category' => null,
        ];
    }

    /**
     * 提取修补失败的错误摘要，便于审计追踪。
     *
     * @param int|null $crawlRunId 抓取运行 ID
     * @param int $threadId 主题 ID
     * @return string|null 错误摘要
     * 无副作用。
     */
    private function resolveRepairErrorContext(?int $crawlRunId, int $threadId): array
    {
        if ($crawlRunId === null) {
            return [
                'summary' => null,
                'category' => self::ERROR_CATEGORY_UNKNOWN,
                'http_error_code' => null,
            ];
        }

        $record = CrawlRunThread::query()
            ->where('crawl_run_id', $crawlRunId)
            ->where('thread_id', $threadId)
            ->first();

        if (!$record instanceof CrawlRunThread) {
            return [
                'summary' => null,
                'category' => self::ERROR_CATEGORY_UNKNOWN,
                'http_error_code' => null,
            ];
        }

        $summary = $record->error_summary;

        return [
            'summary' => $summary,
            'category' => $this->resolveErrorCategory($summary),
            'http_error_code' => $record->http_error_code === null ? null : (int) $record->http_error_code,
        ];
    }

    /**
     * 解析错误摘要对应的归因类别。
     *
     * @param string|null $summary 错误摘要
     * @return string 归因类别
     * 无副作用。
     */
    private function resolveErrorCategory(?string $summary): string
    {
        if ($summary === null || $summary === '') {
            return self::ERROR_CATEGORY_UNKNOWN;
        }

        if (str_starts_with($summary, 'http_') || str_starts_with($summary, 'guest_blocked')) {
            return self::ERROR_CATEGORY_HTTP;
        }

        if (str_starts_with($summary, 'parse_')) {
            return self::ERROR_CATEGORY_PARSE;
        }

        if (str_starts_with($summary, 'db_write_failed')) {
            return self::ERROR_CATEGORY_DB;
        }

        return self::ERROR_CATEGORY_UNKNOWN;
    }

    /**
     * 生成修补后的楼层快照，用于落表记录修补结果。
     *
     * @param int $threadId 主题 ID
     * @return array{max_floor_number:int|null, post_count:int, remaining_floor_count:int|null, remaining_floors:array<int, int>}
     * 无副作用。
     */
    private function buildRepairAfterSnapshot(int $threadId): array
    {
        $thread = Thread::query()->find($threadId);
        $maxFloor = $thread?->crawl_cursor_max_floor_number;
        $postCount = Post::query()
            ->where('thread_id', $threadId)
            ->count();

        $remainingFloors = [];
        if ($maxFloor !== null) {
            $remainingFloors = $this->resolveMissingFloors($threadId, (int) $maxFloor);
        }

        return [
            'max_floor_number' => $maxFloor === null ? null : (int) $maxFloor,
            'post_count' => (int) $postCount,
            'remaining_floor_count' => $maxFloor === null ? null : count($remainingFloors),
            'remaining_floors' => $remainingFloors,
        ];
    }

    /**
     * 基于缺失楼层号估算需要抓取的目标页码列表。
     *
     * @param array<int, int> $missingFloors 缺失楼层号数组（0基）
     * @param int $maxPages 允许抓取的最大页数
     * @param int $pageSizeEstimate 每页楼层数估算值
     * @return array<int, int> 目标页码数组（1基）
     * 无副作用。
     */
    private function estimatePagesToFetch(array $missingFloors, int $maxPages, int $pageSizeEstimate): array
    {
        $normalizedMaxPages = max(1, $maxPages);
        $pageSize = max(1, $pageSizeEstimate);

        if ($missingFloors === []) {
            return [1];
        }

        $bucket = [];
        foreach ($missingFloors as $floor) {
            $floorNumber = (int) $floor;
            if ($floorNumber < 0) {
                continue;
            }
            $page = intdiv($floorNumber, $pageSize) + 1;
            $bucket[$page] = ($bucket[$page] ?? 0) + 1;
        }

        if ($bucket === []) {
            return [1];
        }

        // 业务规则：优先抓“覆盖缺口最多”的页，预算不足时尽量提升修补命中率
        arsort($bucket);
        $seedPages = array_keys($bucket);

        $pages = [];
        foreach ($seedPages as $seedPage) {
            $page = (int) $seedPage;
            if ($page <= 0) {
                continue;
            }
            $pages[] = $page;
            if (count($pages) >= $normalizedMaxPages) {
                break;
            }
        }

        // 风险点：页大小估算可能有偏差，补齐时允许在预算内探测相邻页提升命中率
        $cursor = 0;
        while (count($pages) < $normalizedMaxPages && $cursor < count($pages)) {
            $page = $pages[$cursor];
            $cursor++;

            foreach ([$page - 1, $page + 1] as $neighbor) {
                if ($neighbor <= 0) {
                    continue;
                }
                if (in_array($neighbor, $pages, true)) {
                    continue;
                }
                $pages[] = $neighbor;
                if (count($pages) >= $normalizedMaxPages) {
                    break 2;
                }
            }
        }

        sort($pages);

        return array_values(array_unique(array_map('intval', $pages)));
    }

    /**
     * 按尝试次数拆分缺口楼层（超过上限则跳过修补）。
     *
     * @param int $threadId 主题 ID
     * @param array<int, int> $missingFloors 缺失楼层号数组
     * @param int $maxAttempts 最大尝试次数
     * @return array{pending:array<int, int>, ignored:array<int, int>, attempt_counts:array<int, int>} 拆分结果
     * 无副作用。
     */
    private function splitMissingFloorsByAttemptLimit(
        int $threadId,
        array $missingFloors,
        int $maxAttempts
    ): array {
        if ($missingFloors === []) {
            return ['pending' => [], 'ignored' => [], 'attempt_counts' => []];
        }

        $attempts = ThreadFloorRepairAttempt::query()
            ->where('thread_id', $threadId)
            ->whereIn('floor_number', $missingFloors)
            ->get(['floor_number', 'attempt_count'])
            ->mapWithKeys(function (ThreadFloorRepairAttempt $attempt): array {
                return [(int) $attempt->floor_number => (int) $attempt->attempt_count];
            })
            ->all();

        $pending = [];
        $ignored = [];
        $attemptCounts = [];

        foreach ($missingFloors as $floor) {
            $floorNumber = (int) $floor;
            $attemptCount = (int) ($attempts[$floorNumber] ?? 0);
            $attemptCounts[$floorNumber] = $attemptCount;
            if ($attemptCount >= $maxAttempts) {
                $ignored[] = $floorNumber;
                continue;
            }
            $pending[] = $floorNumber;
        }

        return [
            'pending' => $pending,
            'ignored' => $ignored,
            'attempt_counts' => $attemptCounts,
        ];
    }

    /**
     * 记录本次修补尝试次数，便于限制重复修补。
     *
     * @param int $threadId 主题 ID
     * @param int $sourceThreadId 主题 tid
     * @param array<int, int> $floors 待修补楼层号列表
     * @return void
     * 副作用：写入 thread_floor_repair_attempts。
     */
    private function recordRepairAttempts(int $threadId, int $sourceThreadId, array $floors): void
    {
        if ($floors === []) {
            return;
        }

        $now = CarbonImmutable::now('Asia/Shanghai');
        $existing = ThreadFloorRepairAttempt::query()
            ->where('thread_id', $threadId)
            ->whereIn('floor_number', $floors)
            ->get()
            ->keyBy(fn (ThreadFloorRepairAttempt $attempt): int => (int) $attempt->floor_number);

        foreach ($floors as $floor) {
            $floorNumber = (int) $floor;
            if ($floorNumber < 0) {
                continue;
            }

            $attempt = $existing->get($floorNumber);
            if ($attempt instanceof ThreadFloorRepairAttempt) {
                $attempt->fill([
                    'attempt_count' => (int) $attempt->attempt_count + 1,
                    'last_attempted_at' => $now,
                ]);
                $attempt->save();
                continue;
            }

            ThreadFloorRepairAttempt::create([
                'thread_id' => $threadId,
                'source_thread_id' => $sourceThreadId,
                'floor_number' => $floorNumber,
                'attempt_count' => 1,
                'last_attempted_at' => $now,
            ]);
        }
    }

    /**
     * 写入楼层级审计明细。
     *
     * @param int $auditRunId 审计运行 ID
     * @param ThreadFloorAuditThread $auditThread 审计主题明细
     * @param array<int, int> $missingFloors 缺失楼层列表
     * @param array{pending:array<int, int>, ignored:array<int, int>, attempt_counts:array<int, int>} $filtered 拆分结果
     * @return void
     * 副作用：写入 thread_floor_audit_posts。
     */
    private function createAuditPosts(
        int $auditRunId,
        ThreadFloorAuditThread $auditThread,
        array $missingFloors,
        array $filtered
    ): void {
        if ($missingFloors === []) {
            return;
        }

        $ignoredMap = array_fill_keys($filtered['ignored'], true);
        $attemptCounts = $filtered['attempt_counts'];
        $now = CarbonImmutable::now('Asia/Shanghai');

        $rows = [];
        foreach ($missingFloors as $floor) {
            $floorNumber = (int) $floor;
            if ($floorNumber < 0) {
                continue;
            }
            $status = isset($ignoredMap[$floorNumber]) ? self::POST_STATUS_IGNORED : self::POST_STATUS_MISSING;
            $rows[] = [
                'audit_run_id' => $auditRunId,
                'audit_thread_id' => $auditThread->id,
                'thread_id' => (int) $auditThread->thread_id,
                'source_thread_id' => (int) $auditThread->source_thread_id,
                'floor_number' => $floorNumber,
                'repair_status' => $status,
                'attempt_count_before' => (int) ($attemptCounts[$floorNumber] ?? 0),
                'attempt_count_after' => null,
                'repair_error_category' => null,
                'repair_http_error_code' => null,
                'repair_error_summary' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            ThreadFloorAuditPost::query()->insert($rows);
        }
    }

    /**
     * 标记楼层已尝试修补，并写入尝试次数。
     *
     * @param int $auditThreadId 审计主题明细 ID
     * @param array<int, int> $pendingFloors 需要尝试修补的楼层
     * @param array<int, int> $attemptCounts 尝试次数映射
     * @return void
     * 副作用：更新 thread_floor_audit_posts。
     */
    private function markAuditPostsAttempted(
        int $auditThreadId,
        array $pendingFloors,
        array $attemptCounts
    ): void {
        if ($pendingFloors === []) {
            return;
        }

        $now = CarbonImmutable::now('Asia/Shanghai');
        foreach ($pendingFloors as $floor) {
            $floorNumber = (int) $floor;
            $before = (int) ($attemptCounts[$floorNumber] ?? 0);
            ThreadFloorAuditPost::query()
                ->where('audit_thread_id', $auditThreadId)
                ->where('floor_number', $floorNumber)
                ->update([
                    'attempt_count_after' => $before + 1,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * 标记楼层修补失败。
     *
     * @param int $auditThreadId 审计主题明细 ID
     * @param array<int, int> $pendingFloors 需要尝试修补的楼层
     * @param array{summary:?string, category:string, http_error_code:?int} $errorContext 错误上下文
     * @return void
     * 副作用：更新 thread_floor_audit_posts。
     */
    private function markAuditPostsFailed(int $auditThreadId, array $pendingFloors, array $errorContext): void
    {
        if ($pendingFloors === []) {
            return;
        }

        $now = CarbonImmutable::now('Asia/Shanghai');
        ThreadFloorAuditPost::query()
            ->where('audit_thread_id', $auditThreadId)
            ->whereIn('floor_number', $pendingFloors)
            ->update([
                'repair_status' => self::POST_STATUS_FAILED,
                'repair_error_category' => $errorContext['category'],
                'repair_http_error_code' => $errorContext['http_error_code'],
                'repair_error_summary' => $errorContext['summary'],
                'updated_at' => $now,
            ]);
    }

    /**
     * 标记楼层修补完成情况。
     *
     * @param int $auditThreadId 审计主题明细 ID
     * @param array<int, int> $pendingFloors 需要尝试修补的楼层
     * @param array<int, int> $remainingFloors 修补后仍缺的楼层
     * @return void
     * 副作用：更新 thread_floor_audit_posts。
     */
    private function markAuditPostsAfterRepair(int $auditThreadId, array $pendingFloors, array $remainingFloors): void
    {
        if ($pendingFloors === []) {
            return;
        }

        $remainingMap = array_fill_keys($remainingFloors, true);
        $repaired = [];
        $stillMissing = [];

        foreach ($pendingFloors as $floor) {
            $floorNumber = (int) $floor;
            if (isset($remainingMap[$floorNumber])) {
                $stillMissing[] = $floorNumber;
            } else {
                $repaired[] = $floorNumber;
            }
        }

        $now = CarbonImmutable::now('Asia/Shanghai');
        if ($repaired !== []) {
            ThreadFloorAuditPost::query()
                ->where('audit_thread_id', $auditThreadId)
                ->whereIn('floor_number', $repaired)
                ->update([
                    'repair_status' => self::POST_STATUS_REPAIRED,
                    'updated_at' => $now,
                ]);
        }

        if ($stillMissing !== []) {
            ThreadFloorAuditPost::query()
                ->where('audit_thread_id', $auditThreadId)
                ->whereIn('floor_number', $stillMissing)
                ->update([
                    'repair_status' => self::POST_STATUS_STILL_MISSING,
                    'updated_at' => $now,
                ]);
        }
    }
}
