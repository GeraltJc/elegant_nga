<?php

namespace App\Services\Nga;

use App\Models\CrawlRun;
use App\Models\Forum;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Thread;
use App\Services\Nga\CrawlErrorSummary;
use App\Services\Nga\CrawlRunRecorder;
use App\Services\Nga\Exceptions\NgaParseException;
use App\Services\Nga\Exceptions\NgaRequestException;
use Illuminate\Database\QueryException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * 轻量抓取器：负责列表扫描与主题详情抓取的入库流程。
 */
class NgaLiteCrawler
{
    /**
     * 规则：主题总页数超过该阈值时直接跳过详情抓取。
     */
    private const PAGE_TOTAL_SKIP_LIMIT = 1000;

    /**
     * 规则：内容指纹变化。
     */
    private const CHANGE_REASON_CONTENT = 'content_fingerprint_changed';

    /**
     * 规则：来源标记为删除。
     */
    private const CHANGE_REASON_DELETED = 'marked_deleted_by_source';

    /**
     * 规则：来源标记为折叠。
     */
    private const CHANGE_REASON_FOLDED = 'marked_folded_by_source';

    /**
     * 当前主题处理期间的 HTTP 请求计数器。
     *
     * 业务含义：用于把“主题级请求次数”落表到 crawl_run_threads，方便排查“哪个主题消耗了多少请求”。
     * 风险点：该计数器依赖“串行处理主题”的执行模型；并发抓取时需要改为上下文隔离。
     */
    private ?int $activeThreadHttpRequestCount = null;

    /**
     * 初始化抓取器依赖组件。
     *
     * @param NgaLiteClient $client 网络请求客户端
     * @param NgaLiteListParser $listParser 列表解析器
     * @param NgaLiteThreadParser $threadParser 详情解析器
     * @param NgaPostContentProcessor $contentProcessor 内容处理器（UBB/HTML 清洗）
     */
    public function __construct(
        private readonly NgaLiteClient $client,
        private readonly NgaLiteListParser $listParser,
        private readonly NgaLiteThreadParser $threadParser,
        private readonly NgaPostContentProcessor $contentProcessor
    ) {
    }

    /**
     * 抓取指定版块列表并按增量规则入库主题与楼层。
     *
     * @param int $fid 版块 fid
     * @param int $maxPostPages 单次主题抓取最大页数
     * @param int|null $recentDays 最近自然日窗口（null 表示不过滤）
     * @param int $listPage 列表页码
     * @param CarbonImmutable|null $windowStart 窗口起（可选，默认 recentDays 计算）
     * @param CarbonImmutable|null $windowEnd 窗口止（可选，默认 recentDays 计算）
     * @param string $runTriggerText 运行触发来源
     * @return array{
     *     threads:int,
     *     posts:int,
     *     run_id:int|null,
     *     run_started_at:string|null,
     *     run_finished_at:string|null,
     *     date_window_start:string|null,
     *     date_window_end:string|null,
     *     thread_scanned_count:int,
     *     thread_change_detected_count:int,
     *     thread_updated_count:int,
     *     http_request_count:int,
     *     new_post_count:int,
     *     updated_post_count:int,
     *     failed_thread_count:int,
     *     duration_ms:int|null
     * } 写入数量统计与运行汇总
     * 副作用：写入 forums/threads/posts 等数据。
     */
    public function crawlForum(
        int $fid,
        int $maxPostPages = 5,
        ?int $recentDays = 3,
        int $listPage = 1,
        ?CarbonImmutable $windowStart = null,
        ?CarbonImmutable $windowEnd = null,
        string $runTriggerText = 'manual'
    ): array
    {
        $now = CarbonImmutable::now('Asia/Shanghai');
        if ($windowStart === null && $windowEnd === null) {
            $windowStart = $recentDays ? $now->startOfDay()->subDays($recentDays - 1) : null;
            $windowEnd = $recentDays ? $now->endOfDay() : null;
        }

        $forum = Forum::firstOrCreate(
            ['source_forum_id' => $fid],
            [
                'forum_name' => null,
                'list_url' => $this->defaultListUrl($fid),
                'crawl_page_limit' => $maxPostPages,
                'request_rate_limit_per_sec' => 1.00,
            ]
        );

        $runRecorder = new CrawlRunRecorder();
        $run = $runRecorder->startRun($forum->id, $runTriggerText, $windowStart, $windowEnd, $now);
        $this->configureHttpClientIfSupported($forum, $runRecorder);

        $threadsUpserted = 0;
        $postsUpserted = 0;
        // 业务规则：仅在窗口抓取场景启用“自动翻页”，否则保持手动页码行为
        $shouldAutoPage = $windowStart !== null || $windowEnd !== null;
        $currentPage = max(1, $listPage);

        try {
            while (true) {
                try {
                    $threadsData = $this->listParser->parse($this->client->fetchList($fid, $currentPage));
                } catch (NgaRequestException $exception) {
                    // 业务规则：请求失败应保留原始错误类型，便于区分拦截与解析问题
                    throw $exception;
                } catch (Throwable $exception) {
                    throw new NgaParseException(
                        CrawlErrorSummary::PARSE_LIST_FAILED,
                        $exception->getMessage(),
                        $exception
                    );
                }

                if ($threadsData === []) {
                    break;
                }

                // 业务规则：按“创建时间窗口”判断是否可以停止翻页
                $shouldStopPaging = $shouldAutoPage
                    && $this->shouldStopListPagingByWindow($threadsData, $windowStart, $windowEnd);

                foreach ($threadsData as $threadData) {
                    $sourceThreadId = (int) ($threadData['source_thread_id'] ?? 0);
                    if ($sourceThreadId <= 0) {
                        continue;
                    }

                    $createdAt = $threadData['thread_created_at'] ?? null;
                    $createdAt = $createdAt instanceof CarbonImmutable ? $createdAt : null;
                    $shouldSkipThreadDetail = $this->shouldSkipThreadDetailByWindow($createdAt, $windowStart, $windowEnd);

                    $runRecorder->increaseThreadScannedCount();

                    $runThread = null;

                    try {
                        $thread = Thread::firstOrNew([
                            'forum_id' => $forum->id,
                            'source_thread_id' => $sourceThreadId,
                        ]);

                        if (!$thread->exists) {
                            $thread->first_seen_on_list_page_number = $currentPage;
                        }

                        $lastReplyAt = $threadData['last_reply_at'];
                        // 以 last_reply_at 变化作为增量抓取开关
                        $lastReplyChanged = !$thread->exists || $this->hasLastReplyChanged($thread->last_reply_at, $lastReplyAt);
                        if ($lastReplyChanged) {
                            $thread->last_detected_change_at = $now;
                            $runRecorder->increaseThreadChangeDetectedCount();
                        }

                        $thread->fill([
                            'title' => $threadData['title'],
                            'title_prefix_text' => $threadData['title_prefix_text'],
                            'author_name' => $threadData['author_name'],
                            'author_source_user_id' => $threadData['author_source_user_id'],
                            'thread_created_at' => $createdAt,
                            'last_reply_at' => $lastReplyAt,
                            'reply_count_display' => $threadData['reply_count_display'],
                            'view_count_display' => $threadData['view_count_display'],
                            'is_pinned' => $threadData['is_pinned'],
                            'is_digest' => $threadData['is_digest'],
                            'last_seen_on_list_page_number' => $currentPage,
                        ]);

                        $thread->save();
                        $threadsUpserted++;

                        $runThread = $runRecorder->startThread(
                            $run,
                            $thread,
                            $lastReplyChanged,
                            $lastReplyChanged ? $lastReplyAt : null,
                            CarbonImmutable::now('Asia/Shanghai')
                        );

                        $this->beginThreadHttpRequestTracking();

                        if ($shouldSkipThreadDetail) {
                            // 业务规则：创建时间超过窗口的主题不抓取回复详情
                            $runRecorder->markThreadSuccess(
                                $runThread,
                                CarbonImmutable::now('Asia/Shanghai'),
                                0,
                                false,
                                0,
                                0,
                                $this->endThreadHttpRequestTracking()
                            );
                            continue;
                        }

                        // 兼容旧数据：如果之前因页上限被截断但没有“分段补齐游标”，先补齐游标
                        $this->bootstrapBackfillCursorIfNeeded($thread, $maxPostPages);

                        $threadStartPage = $this->determineThreadStartPage($thread, $lastReplyChanged);
                        $shouldSkipByTotalLimit = (bool) $thread->is_skipped_by_page_total_limit;

                        if ($shouldSkipByTotalLimit) {
                            $runRecorder->markThreadSuccess(
                                $runThread,
                                CarbonImmutable::now('Asia/Shanghai'),
                                0,
                                false,
                                0,
                                0,
                                $this->endThreadHttpRequestTracking()
                            );
                            continue;
                        }

                        if ($threadStartPage === null) {
                            $runRecorder->markThreadSuccess(
                                $runThread,
                                CarbonImmutable::now('Asia/Shanghai'),
                                0,
                                false,
                                0,
                                0,
                                $this->endThreadHttpRequestTracking()
                            );
                            continue;
                        }

                        $threadMaxPostPages = $this->resolveThreadMaxPostPages(
                            $maxPostPages,
                            $createdAt,
                            $windowStart,
                            $windowEnd
                        );

                        $threadAttemptResult = $this->crawlThreadWithRetry(
                            $thread,
                            $threadStartPage,
                            $threadMaxPostPages,
                            $now,
                            $lastReplyChanged,
                            2,
                            $runThread->id
                        );

                        if ($threadAttemptResult['success']) {
                            $threadResult = $threadAttemptResult['result'];
                            $postsUpserted += $threadResult['posts'];
                            $runRecorder->increaseThreadUpdatedCount();
                            $runRecorder->increaseNewPostCount($threadResult['new_posts']);
                            $runRecorder->increaseUpdatedPostCount($threadResult['updated_posts']);
                            $runRecorder->markThreadSuccess(
                                $runThread,
                                CarbonImmutable::now('Asia/Shanghai'),
                                $threadResult['fetched_pages'],
                                $threadResult['page_limit_applied'],
                                $threadResult['new_posts'],
                                $threadResult['updated_posts'],
                                $this->endThreadHttpRequestTracking()
                            );
                        } else {
                            $runRecorder->increaseFailedThreadCount();
                            $runRecorder->markThreadFailure(
                                $runThread,
                                CarbonImmutable::now('Asia/Shanghai'),
                                $threadAttemptResult['http_error_code'],
                                $threadAttemptResult['error_summary'],
                                $this->endThreadHttpRequestTracking()
                            );
                        }
                    } catch (Throwable $exception) {
                        $runRecorder->increaseFailedThreadCount();

                        if ($runThread !== null) {
                            $failure = $this->resolveThreadFailure($exception);
                            $runRecorder->markThreadFailure(
                                $runThread,
                                CarbonImmutable::now('Asia/Shanghai'),
                                $failure['http_error_code'],
                                $failure['summary'],
                                $this->endThreadHttpRequestTracking()
                            );
                        }

                        continue;
                    }
                }

                if (!$shouldAutoPage || $shouldStopPaging) {
                    break;
                }

                $currentPage++;
            }
        } finally {
            $runRecorder->finishRun(CarbonImmutable::now('Asia/Shanghai'));
        }

        $runSummary = $runRecorder->getSummary();

        return [
            'threads' => $threadsUpserted,
            'posts' => $postsUpserted,
            'run_id' => $runSummary['run_id'],
            'run_started_at' => $runSummary['run_started_at'],
            'run_finished_at' => $runSummary['run_finished_at'],
            'date_window_start' => $runSummary['date_window_start'],
            'date_window_end' => $runSummary['date_window_end'],
            'thread_scanned_count' => $runSummary['thread_scanned_count'],
            'thread_change_detected_count' => $runSummary['thread_change_detected_count'],
            'thread_updated_count' => $runSummary['thread_updated_count'],
            'http_request_count' => $runSummary['http_request_count'],
            'new_post_count' => $runSummary['new_post_count'],
            'updated_post_count' => $runSummary['updated_post_count'],
            'failed_thread_count' => $runSummary['failed_thread_count'],
            'duration_ms' => $runSummary['duration_ms'],
        ];
    }

    /**
     * 抓取单个主题并写入楼层数据（支持强制重抓）。
     *
     * @param int $tid 主题 tid
     * @param int $maxPostPages 单次抓取最大页数
     * @param bool $force 是否清空游标后从第一页重抓
     * @param string $runTriggerText 运行触发来源
     * @return array{
     *     thread:int,
     *     posts:int,
     *     run_id:int|null,
     *     run_started_at:string|null,
     *     run_finished_at:string|null,
     *     date_window_start:string|null,
     *     date_window_end:string|null,
     *     thread_scanned_count:int,
     *     thread_change_detected_count:int,
     *     thread_updated_count:int,
     *     http_request_count:int,
     *     new_post_count:int,
     *     updated_post_count:int,
     *     failed_thread_count:int,
     *     duration_ms:int|null
     * } 写入数量统计与运行汇总
     * 副作用：写入 threads/posts 并更新抓取游标。
     * 说明：当主题被标记为超页数跳过且未强制时，仅记录审计结果。
     */
    public function crawlSingleThread(
        int $tid,
        int $maxPostPages = 5,
        bool $force = false,
        string $runTriggerText = 'manual'
    ): array {
        $now = CarbonImmutable::now('Asia/Shanghai');
        $thread = Thread::firstOrNew([
            'source_thread_id' => $tid,
        ]);

        if (!$thread->exists) {
            $thread->forum_id = $this->getOrCreateForumId();
            $thread->thread_created_at = $now;
            $thread->author_name = 'unknown';
            $thread->title = (string) $tid;
        }

        if ($force) {
            // 强制重抓：清空游标与截断标记，确保从第一页重新处理
            $thread->crawl_cursor_max_floor_number = null;
            $thread->crawl_cursor_max_source_post_id = null;
            $thread->crawl_backfill_next_page_number = null;
            $thread->is_truncated_by_page_limit = false;
            $thread->truncated_at_page_number = null;
            $thread->is_skipped_by_page_total_limit = false;
            $thread->skipped_by_page_total_limit_at = null;
        }

        $thread->save();

        if (!$force) {
            $this->bootstrapBackfillCursorIfNeeded($thread, $maxPostPages);
        }

        $forum = Forum::query()->find($thread->forum_id);
        if (!$forum instanceof Forum) {
            $forumId = $this->getOrCreateForumId();
            $forum = Forum::query()->find($forumId);
        }

        $runRecorder = new CrawlRunRecorder();
        $run = $runRecorder->startRun(
            $forum?->id ?? $this->getOrCreateForumId(),
            $runTriggerText,
            null,
            null,
            $now
        );
        if ($forum instanceof Forum) {
            $this->configureHttpClientIfSupported($forum, $runRecorder);
        }

        $runRecorder->increaseThreadScannedCount();

        $runThread = $runRecorder->startThread(
            $run,
            $thread,
            false,
            null,
            CarbonImmutable::now('Asia/Shanghai')
        );

        $this->beginThreadHttpRequestTracking();

        $threadCount = 0;
        $postCount = 0;

        try {
            if (!$force && $thread->is_skipped_by_page_total_limit) {
                $runRecorder->markThreadSuccess(
                    $runThread,
                    CarbonImmutable::now('Asia/Shanghai'),
                    0,
                    false,
                    0,
                    0,
                    $this->endThreadHttpRequestTracking()
                );
            } else {
                $threadAttemptResult = $this->crawlThreadWithRetry(
                    $thread,
                    1,
                    $maxPostPages,
                    $now,
                    true,
                    2,
                    $runThread->id
                );

                if ($threadAttemptResult['success']) {
                    $threadResult = $threadAttemptResult['result'];
                    $threadCount = 1;
                    $postCount = $threadResult['posts'];
                    $runRecorder->increaseThreadUpdatedCount();
                    $runRecorder->increaseNewPostCount($threadResult['new_posts']);
                    $runRecorder->increaseUpdatedPostCount($threadResult['updated_posts']);
                    $runRecorder->markThreadSuccess(
                        $runThread,
                        CarbonImmutable::now('Asia/Shanghai'),
                        $threadResult['fetched_pages'],
                        $threadResult['page_limit_applied'],
                        $threadResult['new_posts'],
                        $threadResult['updated_posts'],
                        $this->endThreadHttpRequestTracking()
                    );
                } else {
                    $runRecorder->increaseFailedThreadCount();
                    $runRecorder->markThreadFailure(
                        $runThread,
                        CarbonImmutable::now('Asia/Shanghai'),
                        $threadAttemptResult['http_error_code'],
                        $threadAttemptResult['error_summary'],
                        $this->endThreadHttpRequestTracking()
                    );
                }
            }
        } finally {
            $runRecorder->finishRun(CarbonImmutable::now('Asia/Shanghai'));
        }

        return [
            'thread' => $threadCount,
            'posts' => $postCount,
            ...$runRecorder->getSummary(),
        ];
    }

    /**
     * 仅抓取指定页码集合，用于缺楼层修补（限制抓取次数，避免顺带拉新过多数据）。
     *
     * 说明：
     * - 该流程只会 upsert “<= capMaxFloorNumber”的楼层，避免把新回复楼层顺带写入。
     * - 不更新 threads 的游标/截断标记，减少对后续增量策略的副作用。
     *
     * @param int $tid 主题 tid
     * @param array<int, int> $pages 需要抓取的页码集合（1基）
     * @param int $capMaxFloorNumber 修补上限最大楼层号（0基）
     * @param string $runTriggerText 运行触发来源
     * @return array{
     *     thread:int,
     *     posts:int,
     *     run_id:int|null,
     *     run_started_at:string|null,
     *     run_finished_at:string|null,
     *     date_window_start:string|null,
     *     date_window_end:string|null,
     *     thread_scanned_count:int,
     *     thread_change_detected_count:int,
     *     thread_updated_count:int,
     *     http_request_count:int,
     *     new_post_count:int,
     *     updated_post_count:int,
     *     failed_thread_count:int,
     *     duration_ms:int|null
     * } 写入数量统计与运行汇总
     * 副作用：写入 posts 以及抓取审计表。
     */
    public function repairThreadMissingFloorsByPages(
        int $tid,
        array $pages,
        int $capMaxFloorNumber,
        string $runTriggerText = 'floor_audit'
    ): array {
        $now = CarbonImmutable::now('Asia/Shanghai');
        $thread = Thread::firstOrNew([
            'source_thread_id' => $tid,
        ]);

        if (!$thread->exists) {
            $thread->forum_id = $this->getOrCreateForumId();
            $thread->thread_created_at = $now;
            $thread->author_name = 'unknown';
            $thread->title = (string) $tid;
            $thread->save();
        }

        $forum = Forum::query()->find($thread->forum_id);
        if (!$forum instanceof Forum) {
            $forumId = $this->getOrCreateForumId();
            $forum = Forum::query()->find($forumId);
        }

        $runRecorder = new CrawlRunRecorder();
        $run = $runRecorder->startRun(
            $forum?->id ?? $this->getOrCreateForumId(),
            $runTriggerText,
            null,
            null,
            $now
        );
        if ($forum instanceof Forum) {
            $this->configureHttpClientIfSupported($forum, $runRecorder);
        }

        $runRecorder->increaseThreadScannedCount();
        $runThread = $runRecorder->startThread(
            $run,
            $thread,
            false,
            null,
            CarbonImmutable::now('Asia/Shanghai')
        );

        $this->beginThreadHttpRequestTracking();

        $threadCount = 0;
        $postCount = 0;

        $normalizedPages = array_values(array_unique(array_filter(array_map('intval', $pages), fn (int $p): bool => $p > 0)));
        sort($normalizedPages);
        if ($normalizedPages === []) {
            $normalizedPages = [1];
        }

        try {
            $processResult = $this->crawlThreadSpecifiedPages(
                $thread,
                $normalizedPages,
                max(0, $capMaxFloorNumber),
                $now,
                $runThread->id
            );

            $threadCount = 1;
            $postCount = $processResult['posts'];
            $runRecorder->increaseThreadUpdatedCount();
            $runRecorder->increaseNewPostCount($processResult['new_posts']);
            $runRecorder->increaseUpdatedPostCount($processResult['updated_posts']);
            $runRecorder->markThreadSuccess(
                $runThread,
                CarbonImmutable::now('Asia/Shanghai'),
                $processResult['fetched_pages'],
                false,
                $processResult['new_posts'],
                $processResult['updated_posts'],
                $this->endThreadHttpRequestTracking()
            );
        } catch (Throwable $exception) {
            $runRecorder->increaseFailedThreadCount();
            $failure = $this->resolveThreadFailure($exception);
            $runRecorder->markThreadFailure(
                $runThread,
                CarbonImmutable::now('Asia/Shanghai'),
                $failure['http_error_code'],
                $failure['summary'],
                $this->endThreadHttpRequestTracking()
            );
        } finally {
            $runRecorder->finishRun(CarbonImmutable::now('Asia/Shanghai'));
        }

        return [
            'thread' => $threadCount,
            'posts' => $postCount,
            ...$runRecorder->getSummary(),
        ];
    }

    /**
     * 在外部传入的运行记录下执行单主题缺楼层修补（不创建/结束 crawl_runs）。
     *
     * 业务含义：用于“缺楼层审计”的批次修补场景，把多个主题的修补归并到同一条 crawl_runs，
     * 从而在运行报表中展示为“一次运行 + 多条主题明细”，并支持统计总请求数等指标。
     *
     * @param CrawlRunRecorder $runRecorder 运行记录器（外部负责 finishRun）
     * @param CrawlRun $run 所属运行记录
     * @param int $tid 主题 tid
     * @param array<int, int> $pages 需要抓取的页码集合（1基）
     * @param int $capMaxFloorNumber 修补上限最大楼层号（0基）
     * @param CarbonImmutable $now 修补执行时刻（上海时区）
     * @return array{thread:int, posts:int, failed:bool} 修补执行结果摘要
     * 副作用：写入 posts 以及 crawl_run_threads。
     */
    public function repairThreadMissingFloorsByPagesInRun(
        CrawlRunRecorder $runRecorder,
        CrawlRun $run,
        int $tid,
        array $pages,
        int $capMaxFloorNumber,
        CarbonImmutable $now
    ): array {
        $forum = Forum::query()->find($run->forum_id);
        if ($forum instanceof Forum) {
            $this->configureHttpClientIfSupported($forum, $runRecorder);
        }

        $thread = Thread::firstOrNew([
            'source_thread_id' => $tid,
        ]);

        if (!$thread->exists) {
            $thread->forum_id = $run->forum_id;
            $thread->thread_created_at = $now;
            $thread->author_name = 'unknown';
            $thread->title = (string) $tid;
            $thread->save();
        }

        $runRecorder->increaseThreadScannedCount();
        $runThread = $runRecorder->startThread(
            $run,
            $thread,
            false,
            null,
            CarbonImmutable::now('Asia/Shanghai')
        );

        $this->beginThreadHttpRequestTracking();

        $threadCount = 0;
        $postCount = 0;
        $failed = false;

        $normalizedPages = array_values(array_unique(array_filter(array_map('intval', $pages), fn (int $p): bool => $p > 0)));
        sort($normalizedPages);
        if ($normalizedPages === []) {
            $normalizedPages = [1];
        }

        try {
            $processResult = $this->crawlThreadSpecifiedPages(
                $thread,
                $normalizedPages,
                max(0, $capMaxFloorNumber),
                $now,
                $runThread->id
            );

            $threadCount = 1;
            $postCount = $processResult['posts'];
            $runRecorder->increaseThreadUpdatedCount();
            $runRecorder->increaseNewPostCount($processResult['new_posts']);
            $runRecorder->increaseUpdatedPostCount($processResult['updated_posts']);
            $runRecorder->markThreadSuccess(
                $runThread,
                CarbonImmutable::now('Asia/Shanghai'),
                $processResult['fetched_pages'],
                false,
                $processResult['new_posts'],
                $processResult['updated_posts'],
                $this->endThreadHttpRequestTracking()
            );
        } catch (Throwable $exception) {
            $failed = true;
            $runRecorder->increaseFailedThreadCount();
            $failure = $this->resolveThreadFailure($exception);
            $runRecorder->markThreadFailure(
                $runThread,
                CarbonImmutable::now('Asia/Shanghai'),
                $failure['http_error_code'],
                $failure['summary'],
                $this->endThreadHttpRequestTracking()
            );
        }

        return [
            'thread' => $threadCount,
            'posts' => $postCount,
            'failed' => $failed,
        ];
    }

    /**
     * 抓取指定页码集合并 upsert 楼层数据（带楼层上限）。
     *
     * @param Thread $thread 主题模型
     * @param array<int, int> $pages 页码集合（1基）
     * @param int $capMaxFloorNumber 修补上限最大楼层号（0基）
     * @param CarbonImmutable $now 抓取时刻
     * @param int|null $crawlRunThreadId 抓取明细 ID
     * @return array{posts:int, new_posts:int, updated_posts:int, fetched_pages:int}
     * 副作用：写入 posts 与 post_revisions。
     */
    private function crawlThreadSpecifiedPages(
        Thread $thread,
        array $pages,
        int $capMaxFloorNumber,
        CarbonImmutable $now,
        ?int $crawlRunThreadId
    ): array {
        $postsUpserted = 0;
        $newPostCount = 0;
        $updatedPostCount = 0;
        $fetchedPageCount = 0;
        $knownPageTotal = null;

        foreach ($pages as $page) {
            if ($page <= 0) {
                // 业务规则：页码必须为正整数，避免无效请求
                continue;
            }
            if ($knownPageTotal !== null && $page > $knownPageTotal) {
                // 业务规则：页码已排序，超过已知总页数的后续页均不再请求，避免无意义请求
                break;
            }

            try {
                $pageData = $this->threadParser->parse($this->client->fetchThread($thread->source_thread_id, $page));
            } catch (NgaRequestException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new NgaParseException(
                    CrawlErrorSummary::PARSE_THREAD_FAILED,
                    $exception->getMessage(),
                    $exception
                );
            }

            $fetchedPageCount++;
            $pageTotal = (int) ($pageData['page_total'] ?? 0);
            if ($pageTotal > 0) {
                $knownPageTotal = $knownPageTotal === null ? $pageTotal : max($knownPageTotal, $pageTotal);
            }

            $threadTitle = trim((string) ($pageData['thread_title'] ?? ''));

            foreach ($pageData['posts'] as $postData) {
                $sourcePostId = (int) ($postData['source_post_id'] ?? 0);
                $floorNumber = (int) ($postData['floor_number'] ?? 0);
                if ($floorNumber < 0) {
                    continue;
                }
                if ($floorNumber > $capMaxFloorNumber) {
                    // 业务规则：仅补齐缺口范围内楼层，避免把新回复楼层顺带写入
                    continue;
                }
                if ($sourcePostId <= 0) {
                    $sourcePostId = $floorNumber;
                }

                $content = (string) ($postData['content_raw'] ?? '');
                $contentFormat = (string) ($postData['content_format'] ?? 'ubb');
                $contentHtml = $this->contentProcessor->toSafeHtml($content, $contentFormat);
                if ($floorNumber === 0 && $threadTitle !== '') {
                    $contentHtml = $this->stripLeadingThreadTitle($contentHtml, $threadTitle);
                }
                $fingerprint = hash('sha256', $content);

                $post = $this->findPostForUpsert($thread, $sourcePostId, $floorNumber);

                $wasExisting = $post->exists;
                $previousSnapshot = $this->buildPostSnapshot($post);
                $currentDeleted = (bool) ($postData['is_deleted_by_source'] ?? false);
                $currentFolded = (bool) ($postData['is_folded_by_source'] ?? false);
                $sourceEditedAt = $postData['source_edited_at'] ?? null;

                $post->fill([
                    'source_post_id' => $sourcePostId,
                    'floor_number' => $floorNumber,
                    'author_name' => $postData['author_name'],
                    'author_source_user_id' => $postData['author_source_user_id'],
                    'post_created_at' => $postData['post_created_at'],
                    'content_html' => $contentHtml,
                    // 业务规则：指纹必须基于原始内容，避免清洗导致误判
                    'content_fingerprint_sha256' => $fingerprint,
                    'is_deleted_by_source' => $currentDeleted,
                    'is_folded_by_source' => $currentFolded,
                ]);

                $changeEvaluation = $this->evaluatePostChanges(
                    $wasExisting,
                    $previousSnapshot,
                    $fingerprint,
                    $currentDeleted,
                    $currentFolded
                );

                if ($changeEvaluation['should_record_revision']) {
                    // 业务规则：仅对“已有楼层”的变化写入历史版本
                    $this->recordPostRevision(
                        $post,
                        $previousSnapshot,
                        $now,
                        $sourceEditedAt,
                        $changeEvaluation['reasons'],
                        $crawlRunThreadId
                    );
                }

                if ($changeEvaluation['has_any_change']) {
                    // 业务规则：新增或变更都要刷新“内容最后变化时间”
                    $post->content_last_changed_at = $now;
                }

                $post->save();
                if ($changeEvaluation['has_any_change']) {
                    $postsUpserted++;
                    if (!$wasExisting) {
                        $newPostCount++;
                    } else {
                        $updatedPostCount++;
                    }
                }
            }
        }

        return [
            'posts' => $postsUpserted,
            'new_posts' => $newPostCount,
            'updated_posts' => $updatedPostCount,
            'fetched_pages' => $fetchedPageCount,
        ];
    }

    /**
     * 抓取主题的一个分页片段，并更新楼层游标与截断标记。
     *
     * @param Thread $thread 主题模型
     * @param int $startPage 起始页码
     * @param int $maxPostPages 单次抓取最大页数
     * @param CarbonImmutable $now 抓取时刻（上海时区）
     * @param bool $shouldCheckExistingPosts 是否复查已抓楼层（用于编辑/删除/折叠检测）
     * @param int|null $crawlRunThreadId 抓取明细 ID（用于关联版本记录）
     * @return array{posts:int, new_posts:int, updated_posts:int, fetched_pages:int, page_limit_applied:bool} 写入楼层数
     * 副作用：写入 posts 并更新 threads 的抓取游标。
     */
    private function crawlThreadSegment(
        Thread $thread,
        int $startPage,
        int $maxPostPages,
        CarbonImmutable $now,
        bool $shouldCheckExistingPosts,
        ?int $crawlRunThreadId = null
    ): array
    {
        $page = max(1, $startPage);
        $pageTotal = 1;
        $postsUpserted = 0;
        $newPostCount = 0;
        $updatedPostCount = 0;
        $fetchedPageCount = 0;
        $maxFloor = $thread->crawl_cursor_max_floor_number;
        $maxPid = $thread->crawl_cursor_max_source_post_id;
        $endPageFetched = null;
        $replyCountTotalFromDetail = null;

        $pageLimitEnd = $page + max(1, $maxPostPages) - 1;

        while ($page <= $pageLimitEnd) {
            try {
                $pageData = $this->threadParser->parse($this->client->fetchThread($thread->source_thread_id, $page));
            } catch (NgaRequestException $exception) {
                // 业务规则：请求失败不视为解析失败，交由上层归类为请求错误
                throw $exception;
            } catch (Throwable $exception) {
                throw new NgaParseException(
                    CrawlErrorSummary::PARSE_THREAD_FAILED,
                    $exception->getMessage(),
                    $exception
                );
            }

            $fetchedPageCount++;
            $pageTotal = max($pageTotal, (int) $pageData['page_total']);
            if ($replyCountTotalFromDetail === null && array_key_exists('reply_count_total', $pageData)) {
                $replyCountCandidate = $pageData['reply_count_total'];
                if (is_int($replyCountCandidate) && $replyCountCandidate >= 0) {
                    $replyCountTotalFromDetail = $replyCountCandidate;
                }
            }

            $thread->crawl_page_total_last_seen = $pageTotal;
            $threadTitle = trim((string) ($pageData['thread_title'] ?? ''));
            if ($this->shouldUpdateThreadTitle($thread->title, $threadTitle)) {
                // 关键规则：仅在旧标题为空/纯数字时才用详情页标题纠正
                $thread->title = $threadTitle;
                $thread->title_prefix_text = $this->extractTitlePrefix($threadTitle);
                $thread->title_last_changed_at = $now;
            }

            // 超过页数上限的主题不抓取 posts，仅更新 threads 的列表字段与跳过标记
            if ($pageTotal > self::PAGE_TOTAL_SKIP_LIMIT) {
                if (!$thread->is_skipped_by_page_total_limit) {
                    $thread->is_skipped_by_page_total_limit = true;
                    $thread->skipped_by_page_total_limit_at = $now;
                }
                if ($replyCountTotalFromDetail !== null) {
                    // 业务规则：详情页提供的回复数更可信，用于纠正列表页 reply_count_display
                    $thread->reply_count_display = $replyCountTotalFromDetail;
                }
                $thread->crawl_backfill_next_page_number = null;
                $thread->is_truncated_by_page_limit = true;
                $thread->truncated_at_page_number = null;
                $thread->save();

                return [
                    'posts' => 0,
                    'new_posts' => 0,
                    'updated_posts' => 0,
                    'fetched_pages' => $fetchedPageCount,
                    'page_limit_applied' => false,
                ];
            }

            foreach ($pageData['posts'] as $postData) {
                $sourcePostId = (int) ($postData['source_post_id'] ?? 0);
                $floorNumber = (int) ($postData['floor_number'] ?? 0);
                if ($floorNumber < 0) {
                    continue;
                }
                if ($sourcePostId <= 0) {
                    $sourcePostId = $floorNumber;
                }
                // 业务规则：仅在“新增楼层模式”下跳过游标内楼层，复查模式需继续比对历史版本
                if (
                    !$shouldCheckExistingPosts
                    && $this->shouldSkipByCursor($floorNumber, $sourcePostId, $maxFloor, $maxPid)
                ) {
                    continue;
                }

                $content = (string) ($postData['content_raw'] ?? '');
                $contentFormat = (string) ($postData['content_format'] ?? 'ubb');
                $contentHtml = $this->contentProcessor->toSafeHtml($content, $contentFormat);
                if ($floorNumber === 0 && $threadTitle !== '') {
                    $contentHtml = $this->stripLeadingThreadTitle($contentHtml, $threadTitle);
                }
                $fingerprint = hash('sha256', $content);

                $post = $this->findPostForUpsert($thread, $sourcePostId, $floorNumber);

                $wasExisting = $post->exists;
                $previousSnapshot = $this->buildPostSnapshot($post);
                $currentDeleted = (bool) ($postData['is_deleted_by_source'] ?? false);
                $currentFolded = (bool) ($postData['is_folded_by_source'] ?? false);
                $sourceEditedAt = $postData['source_edited_at'] ?? null;

                $post->fill([
                    'source_post_id' => $sourcePostId,
                    'floor_number' => $floorNumber,
                    'author_name' => $postData['author_name'],
                    'author_source_user_id' => $postData['author_source_user_id'],
                    'post_created_at' => $postData['post_created_at'],
                    'content_html' => $contentHtml,
                    // 业务规则：指纹必须基于原始内容，避免清洗导致误判
                    'content_fingerprint_sha256' => $fingerprint,
                    'is_deleted_by_source' => $currentDeleted,
                    'is_folded_by_source' => $currentFolded,
                ]);

                $changeEvaluation = $this->evaluatePostChanges(
                    $wasExisting,
                    $previousSnapshot,
                    $fingerprint,
                    $currentDeleted,
                    $currentFolded
                );

                if ($changeEvaluation['should_record_revision']) {
                    // 业务规则：仅对“已有楼层”的变化写入历史版本
                    $this->recordPostRevision(
                        $post,
                        $previousSnapshot,
                        $now,
                        $sourceEditedAt,
                        $changeEvaluation['reasons'],
                        $crawlRunThreadId
                    );
                }

                if ($changeEvaluation['has_any_change']) {
                    // 业务规则：新增或变更都要刷新“内容最后变化时间”
                    $post->content_last_changed_at = $now;
                }

                $post->save();
                if ($changeEvaluation['has_any_change']) {
                    $postsUpserted++;
                    if (!$wasExisting) {
                        $newPostCount++;
                    } else {
                        $updatedPostCount++;
                    }
                }

                $maxFloor = $maxFloor === null ? $floorNumber : max($maxFloor, $floorNumber);
                $maxPid = $maxPid === null ? $sourcePostId : max($maxPid, $sourcePostId);
            }

            $endPageFetched = $page;
            if ($page >= $pageTotal) {
                break;
            }

            $page++;
        }

        $endPageFetched = $endPageFetched ?? $startPage;
        $hasMorePages = $endPageFetched < $pageTotal;

        $resolvedReplyCountDisplay = null;
        if ($replyCountTotalFromDetail !== null) {
            $resolvedReplyCountDisplay = $replyCountTotalFromDetail;
        } elseif (!$hasMorePages && $maxFloor !== null) {
            // 业务规则：当抓到最后一页时，最大楼层号即为“回复数”（不含楼主 0 楼）
            $resolvedReplyCountDisplay = max(0, (int) $maxFloor);
        }

        $thread->fill([
            'last_crawled_at' => $now,
            'crawl_cursor_max_floor_number' => $maxFloor,
            'crawl_cursor_max_source_post_id' => $maxPid,
            // 语义：当前是否仍未抓全（受“单次最多抓 N 页”限制）
            'is_truncated_by_page_limit' => $hasMorePages,
            // 记录本次抓取到的最后页码（绝对页码），用于排查与提示
            'truncated_at_page_number' => $hasMorePages ? $endPageFetched : null,
            // 分段补齐游标：下次从最后抓到的页码+1开始
            'crawl_backfill_next_page_number' => $hasMorePages ? ($endPageFetched + 1) : null,
            // 业务规则：回复数展示优先信任详情页口径；仅在“已抓到末页”时才用楼层号兜底
            'reply_count_display' => $resolvedReplyCountDisplay === null
                ? (int) $thread->reply_count_display
                : $resolvedReplyCountDisplay,
        ]);
        $thread->save();

        return [
            'posts' => $postsUpserted,
            'new_posts' => $newPostCount,
            'updated_posts' => $updatedPostCount,
            'fetched_pages' => $fetchedPageCount,
            'page_limit_applied' => $hasMorePages,
        ];
    }

    /**
     * 带主题级重试的抓取执行器（兜底一次）。
     *
     * @param Thread $thread 主题模型
     * @param int $startPage 起始页码
     * @param int $maxPostPages 单次抓取最大页数
     * @param CarbonImmutable $now 抓取时刻
     * @param bool $shouldCheckExistingPosts 是否复查已抓楼层
     * @param int $maxAttempts 最大尝试次数
     * @param int|null $crawlRunThreadId 抓取明细 ID
     * @return array{
     *     success:bool,
     *     result:array{posts:int, new_posts:int, updated_posts:int, fetched_pages:int, page_limit_applied:bool}|null,
     *     error_summary:string|null,
     *     http_error_code:int|null
     * } 执行结果
     * 副作用：可能写入 posts 与 threads 游标。
     */
    private function crawlThreadWithRetry(
        Thread $thread,
        int $startPage,
        int $maxPostPages,
        CarbonImmutable $now,
        bool $shouldCheckExistingPosts,
        int $maxAttempts,
        ?int $crawlRunThreadId
    ): array {
        $attempt = 1;
        $normalizedMaxAttempts = max(1, $maxAttempts);

        while ($attempt <= $normalizedMaxAttempts) {
            try {
                $result = $this->crawlThreadSegment(
                    $thread,
                    $startPage,
                    $maxPostPages,
                    $now,
                    $shouldCheckExistingPosts,
                    $crawlRunThreadId
                );

                return [
                    'success' => true,
                    'result' => $result,
                    'error_summary' => null,
                    'http_error_code' => null,
                ];
            } catch (Throwable $exception) {
                $shouldRetry = $attempt < $normalizedMaxAttempts;
                if (!$shouldRetry) {
                    $failure = $this->resolveThreadFailure($exception);

                    return [
                        'success' => false,
                        'result' => null,
                        'error_summary' => $failure['summary'],
                        'http_error_code' => $failure['http_error_code'],
                    ];
                }
            }

            $attempt++;
        }

        return [
            'success' => false,
            'result' => null,
            'error_summary' => CrawlErrorSummary::UNKNOWN_ERROR,
            'http_error_code' => null,
        ];
    }

    /**
     * 将异常映射为可审计的错误摘要与 HTTP 状态码。
     *
     * @param Throwable $exception 异常对象
     * @return array{summary:string, http_error_code:int|null} 错误摘要与状态码
     * 无副作用。
     */
    private function resolveThreadFailure(Throwable $exception): array
    {
        if ($exception instanceof NgaRequestException) {
            return [
                'summary' => $exception->getSummaryToken(),
                'http_error_code' => $exception->getStatusCode(),
            ];
        }

        if ($exception instanceof NgaParseException) {
            return [
                'summary' => $exception->getSummaryToken(),
                'http_error_code' => null,
            ];
        }

        if ($exception instanceof QueryException) {
            return [
                'summary' => CrawlErrorSummary::DB_WRITE_FAILED,
                'http_error_code' => null,
            ];
        }

        return [
            'summary' => CrawlErrorSummary::formatWithMessage(
                CrawlErrorSummary::UNKNOWN_ERROR,
                $exception->getMessage()
            ),
            'http_error_code' => null,
        ];
    }

    /**
     * 若当前客户端支持 HTTP 统计与限速，则注入相关配置。
     *
     * 说明：仅当 request_rate_limit_per_sec 为正数时启用限速。
     *
     * @param Forum $forum 论坛配置
     * @param CrawlRunRecorder $runRecorder 运行记录器
     * @return void
     * 无副作用。
     */
    private function configureHttpClientIfSupported(Forum $forum, CrawlRunRecorder $runRecorder): void
    {
        if (!$this->client instanceof HttpNgaLiteClient) {
            return;
        }

        $rateLimitPerSec = is_numeric($forum->request_rate_limit_per_sec)
            ? (float) $forum->request_rate_limit_per_sec
            : null;

        $shouldEnableRateLimit = $rateLimitPerSec !== null && $rateLimitPerSec > 0;
        if ($shouldEnableRateLimit) {
            $this->client->setRequestRateLimitPerSec($rateLimitPerSec);
        }

        $this->client->setRequestAttemptObserver(function () use ($runRecorder): void {
            $runRecorder->increaseHttpRequestCount();
            if ($this->activeThreadHttpRequestCount !== null) {
                $this->activeThreadHttpRequestCount++;
            }
        });
    }

    /**
     * 开始统计“主题级 HTTP 请求次数”。
     *
     * 业务含义：把一个主题处理期间的请求量落到 crawl_run_threads，便于成本与异常排查。
     *
     * @return void
     * 副作用：重置内部计数器。
     */
    private function beginThreadHttpRequestTracking(): void
    {
        $this->activeThreadHttpRequestCount = 0;
    }

    /**
     * 结束统计“主题级 HTTP 请求次数”并返回计数值。
     *
     * @return int 请求次数
     * 副作用：清空内部计数器。
     */
    private function endThreadHttpRequestTracking(): int
    {
        $count = $this->activeThreadHttpRequestCount ?? 0;
        $this->activeThreadHttpRequestCount = null;

        return $count;
    }

    /**
     * 构建楼层当前状态快照，供变更对比与历史记录使用。
     *
     * @param Post $post 楼层模型
     * @return array{content_html:string, content_fingerprint_sha256:string, is_deleted_by_source:bool, is_folded_by_source:bool}
     * 无副作用。
     */
    private function buildPostSnapshot(Post $post): array
    {
        return [
            'content_html' => (string) $post->content_html,
            'content_fingerprint_sha256' => (string) $post->content_fingerprint_sha256,
            'is_deleted_by_source' => (bool) $post->is_deleted_by_source,
            'is_folded_by_source' => (bool) $post->is_folded_by_source,
        ];
    }

    /**
     * 根据 pid 与楼层号查找楼层记录，避免 pid 变化导致唯一冲突。
     *
     * @param Thread $thread 主题模型
     * @param int $sourcePostId 来源 pid
     * @param int $floorNumber 楼层号
     * @return Post 楼层模型（可能为新实例）
     * 副作用：无。
     */
    private function findPostForUpsert(Thread $thread, int $sourcePostId, int $floorNumber): Post
    {
        $post = Post::where('thread_id', $thread->id)
            ->where('source_post_id', $sourcePostId)
            ->first();
        if ($post instanceof Post) {
            return $post;
        }

        // 业务规则：pid 可能缺失或变化，使用楼层号兜底匹配
        $post = Post::where('thread_id', $thread->id)
            ->where('floor_number', $floorNumber)
            ->first();
        if ($post instanceof Post) {
            return $post;
        }

        return new Post([
            'thread_id' => $thread->id,
            'source_post_id' => $sourcePostId,
        ]);
    }

    /**
     * 判断楼层是否发生变更，并给出变更原因列表。
     *
     * @param bool $wasExisting 是否为已存在楼层
     * @param array{content_html:string, content_fingerprint_sha256:string, is_deleted_by_source:bool, is_folded_by_source:bool} $previousSnapshot
     * @param string $currentFingerprint 当前内容指纹（基于原始内容）
     * @param bool $currentDeleted 当前删除状态
     * @param bool $currentFolded 当前折叠状态
     * @return array{has_any_change:bool, should_record_revision:bool, reasons:array<int, string>}
     * 无副作用。
     */
    private function evaluatePostChanges(
        bool $wasExisting,
        array $previousSnapshot,
        string $currentFingerprint,
        bool $currentDeleted,
        bool $currentFolded
    ): array {
        // 业务规则：仅比较已有楼层，避免首次入库误判为“变更”
        $hasContentChanged = $wasExisting && $previousSnapshot['content_fingerprint_sha256'] !== $currentFingerprint;
        $hasDeletedChanged = $wasExisting && $previousSnapshot['is_deleted_by_source'] !== $currentDeleted;
        $hasFoldedChanged = $wasExisting && $previousSnapshot['is_folded_by_source'] !== $currentFolded;

        // 业务规则：变更原因顺序固定，便于审计与测试对齐
        $reasons = [];
        if ($hasContentChanged) {
            $reasons[] = self::CHANGE_REASON_CONTENT;
        }
        if ($hasDeletedChanged) {
            $reasons[] = self::CHANGE_REASON_DELETED;
        }
        if ($hasFoldedChanged) {
            $reasons[] = self::CHANGE_REASON_FOLDED;
        }

        // 业务规则：新增也视为变化，用于刷新 content_last_changed_at
        $hasAnyChange = !$wasExisting || $hasContentChanged || $hasDeletedChanged || $hasFoldedChanged;

        return [
            'has_any_change' => $hasAnyChange,
            'should_record_revision' => $wasExisting && $reasons !== [],
            'reasons' => $reasons,
        ];
    }

    /**
     * 写入楼层历史版本记录（仅保存旧内容快照）。
     *
     * @param Post $post 楼层模型（必须已存在）
     * @param array{content_html:string, content_fingerprint_sha256:string, is_deleted_by_source:bool, is_folded_by_source:bool} $previousSnapshot
     * @param CarbonImmutable $now 抓取时刻（上海时区）
     * @param CarbonInterface|null $sourceEditedAt 来源编辑时间（若可得）
     * @param array<int, string> $reasons 变更原因 token 列表
     * @param int|null $crawlRunThreadId 抓取明细 ID
     * 副作用：写入 post_revisions。
     */
    private function recordPostRevision(
        Post $post,
        array $previousSnapshot,
        CarbonImmutable $now,
        ?CarbonInterface $sourceEditedAt,
        array $reasons,
        ?int $crawlRunThreadId
    ): void {
        if (!$post->exists || $post->id === null) {
            // 防御性检查：仅对已存在楼层写入历史记录
            return;
        }

        if ($reasons === []) {
            return;
        }

        PostRevision::create([
            'post_id' => $post->id,
            'revision_created_at' => $now,
            'source_edited_at' => $sourceEditedAt,
            'content_html' => $previousSnapshot['content_html'],
            'content_fingerprint_sha256' => $previousSnapshot['content_fingerprint_sha256'],
            'change_detected_reason' => implode(';', $reasons),
            'crawl_run_thread_id' => $crawlRunThreadId,
        ]);
    }

    /**
     * 兼容旧数据：补齐分段补齐游标。
     *
     * @param Thread $thread 主题模型
     * @param int $maxPostPages 单次抓取页上限
     * 副作用：更新 threads 抓取游标。
     */
    private function bootstrapBackfillCursorIfNeeded(Thread $thread, int $maxPostPages): void
    {
        if ($thread->is_skipped_by_page_total_limit) {
            return;
        }

        if ($thread->crawl_backfill_next_page_number !== null) {
            return;
        }

        // 兼容旧逻辑：旧版只抓前 N 页，若当时已标记截断，则默认从 N+1 开始续抓补齐
        if (!$thread->is_truncated_by_page_limit) {
            return;
        }

        $truncatedAt = (int) ($thread->truncated_at_page_number ?? 0);
        if ($truncatedAt <= 0) {
            $truncatedAt = max(1, $maxPostPages);
        }

        $thread->crawl_backfill_next_page_number = $truncatedAt + 1;
        $thread->save();
    }

    /**
     * 根据抓取状态决定主题抓取起始页码。
     *
     * @param Thread $thread 主题模型
     * @param bool $lastReplyChanged 是否检测到 last_reply_at 变化
     * @return int|null 起始页码（null 表示无需抓取）
     * 无副作用。
     */
    private function determineThreadStartPage(Thread $thread, bool $lastReplyChanged): ?int
    {
        // 优先续抓补齐段：即使 last_reply_at 不变也要持续补齐直到抓全
        if ($thread->crawl_backfill_next_page_number !== null) {
            return (int) $thread->crawl_backfill_next_page_number;
        }

        // 已补齐时：仅当 last_reply_at 变化，才从“上次已知的最后一页”开始增量探测与追加
        if ($lastReplyChanged) {
            $knownTotal = (int) ($thread->crawl_page_total_last_seen ?? 0);

            return $knownTotal > 0 ? $knownTotal : 1;
        }

        return null;
    }

    /**
     * 判断主题创建时间是否落在抓取窗口内。
     *
     * @param CarbonImmutable|null $createdAt 主题创建时间
     * @param CarbonImmutable|null $windowStart 窗口起始时间
     * @param CarbonImmutable|null $windowEnd 窗口结束时间
     * @return bool 是否属于窗口
     * 无副作用。
     */
    private function isThreadWithinWindow(
        ?CarbonImmutable $createdAt,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd
    ): bool {
        $hasWindow = $windowStart !== null || $windowEnd !== null;
        // 业务规则：未指定窗口时视为全部命中
        if (!$hasWindow) {
            return true;
        }

        // 业务规则：创建时间缺失时，避免误判导致漏抓
        if (!$createdAt instanceof CarbonImmutable) {
            return true;
        }

        // 业务规则：窗口起止都需参与判断，任一越界即视为不命中
        $isBeforeWindowStart = $windowStart instanceof CarbonImmutable && $createdAt->lt($windowStart);
        $isAfterWindowEnd = $windowEnd instanceof CarbonImmutable && $createdAt->gt($windowEnd);

        return !$isBeforeWindowStart && !$isAfterWindowEnd;
    }

    /**
     * 判断是否需要因窗口规则跳过主题详情抓取。
     *
     * @param CarbonImmutable|null $createdAt 主题创建时间
     * @param CarbonImmutable|null $windowStart 窗口起始时间
     * @param CarbonImmutable|null $windowEnd 窗口结束时间
     * @return bool 是否跳过详情抓取
     * 无副作用。
     */
    private function shouldSkipThreadDetailByWindow(
        ?CarbonImmutable $createdAt,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd
    ): bool {
        $hasWindow = $windowStart !== null || $windowEnd !== null;
        if (!$hasWindow) {
            return false;
        }

        if (!$createdAt instanceof CarbonImmutable) {
            return false;
        }

        $isInWindow = $this->isThreadWithinWindow($createdAt, $windowStart, $windowEnd);

        // 业务规则：创建时间超出窗口的主题不抓取回复详情
        return !$isInWindow;
    }

    /**
     * 根据窗口规则决定主题详情抓取的页数上限。
     *
     * @param int $maxPostPages 原始页数上限
     * @param CarbonImmutable|null $createdAt 主题创建时间
     * @param CarbonImmutable|null $windowStart 窗口起始时间
     * @param CarbonImmutable|null $windowEnd 窗口结束时间
     * @return int 详情抓取页数上限
     * 无副作用。
     */
    private function resolveThreadMaxPostPages(
        int $maxPostPages,
        ?CarbonImmutable $createdAt,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd
    ): int {
        $hasWindow = $windowStart !== null || $windowEnd !== null;
        if (!$hasWindow) {
            return $maxPostPages;
        }

        $isInWindow = $this->isThreadWithinWindow($createdAt, $windowStart, $windowEnd);

        // 业务规则：窗口内主题需抓到最后一页，用跳过阈值作为安全上限
        if ($isInWindow) {
            return self::PAGE_TOTAL_SKIP_LIMIT;
        }

        return $maxPostPages;
    }

    /**
     * 判断列表页是否满足“可停止翻页”的条件。
     *
     * @param array<int, array<string, mixed>> $threadsData 列表页解析结果
     * @param CarbonImmutable|null $windowStart 窗口起始时间
     * @param CarbonImmutable|null $windowEnd 窗口结束时间
     * @return bool 是否可停止继续翻页
     * 无副作用。
     */
    private function shouldStopListPagingByWindow(
        array $threadsData,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd
    ): bool {
        $hasWindow = $windowStart !== null || $windowEnd !== null;
        if (!$hasWindow) {
            return false;
        }

        $hasNonPinnedThread = false;
        $hasNonPinnedInWindow = false;
        $hasNonPinnedOutOfWindow = false;

        foreach ($threadsData as $threadData) {
            $isPinned = (bool) ($threadData['is_pinned'] ?? false);
            if ($isPinned) {
                continue;
            }

            $hasNonPinnedThread = true;

            $createdAt = $threadData['thread_created_at'] ?? null;
            $createdAt = $createdAt instanceof CarbonImmutable ? $createdAt : null;
            $isInWindow = $this->isThreadWithinWindow($createdAt, $windowStart, $windowEnd);

            if ($isInWindow) {
                $hasNonPinnedInWindow = true;
                continue;
            }

            $hasNonPinnedOutOfWindow = true;
        }

        // 业务规则：当“非置顶主题”均已过窗口时，后续页无需继续抓取
        $allNonPinnedOutOfWindow = $hasNonPinnedThread && !$hasNonPinnedInWindow && $hasNonPinnedOutOfWindow;

        return $allNonPinnedOutOfWindow;
    }

    /**
     * 判断最后回复时间是否发生变化（用于增量开关）。
     *
     * @param CarbonInterface|null $previous 上次记录的时间
     * @param CarbonInterface|null $current 本次抓取的时间
     * @return bool 是否变化
     * 无副作用。
     */
    private function hasLastReplyChanged(?CarbonInterface $previous, ?CarbonInterface $current): bool
    {
        if ($previous === null && $current === null) {
            return false;
        }

        if ($previous === null || $current === null) {
            return true;
        }

        // 以本地时间字符串比较，避免时区差异导致误判
        return $previous->format('Y-m-d H:i:s') !== $current->format('Y-m-d H:i:s');
    }

    /**
     * 基于游标判断是否跳过已抓楼层。
     *
     * @param int $floorNumber 当前楼层号
     * @param int $sourcePostId 当前 pid
     * @param int|null $maxFloor 已抓到的最大楼层号
     * @param int|null $maxPid 已抓到的最大 pid
     * @return bool 是否应跳过
     * 无副作用。
     */
    private function shouldSkipByCursor(int $floorNumber, int $sourcePostId, ?int $maxFloor, ?int $maxPid): bool
    {
        // 只抓“新增楼层”口径：任一游标命中即可跳过（避免重复写入与唯一约束冲突）
        if ($maxFloor !== null) {
            return $floorNumber <= $maxFloor;
        }

        if ($maxPid !== null) {
            return $sourcePostId <= $maxPid;
        }

        return false;
    }

    /**
     * 构建列表页默认 URL（访客模式）。
     *
     * @param int $fid 版块 fid
     * @return string 列表页 URL
     * 无副作用。
     */
    private function defaultListUrl(int $fid): string
    {
        // 访客模式不走 lite=js
        return "https://nga.178.com/thread.php?fid={$fid}&order_by=postdatedesc";
    }

    /**
     * 获取或创建默认 fid=7 的论坛 ID。
     *
     * @return int forums.id
     * 副作用：必要时写入 forums。
     */
    private function getOrCreateForumId(): int
    {
        $forum = Forum::firstOrCreate(
            ['source_forum_id' => 7],
            [
                'forum_name' => null,
                'list_url' => $this->defaultListUrl(7),
                'crawl_page_limit' => 5,
                'request_rate_limit_per_sec' => 1.00,
            ]
        );

        return (int) $forum->id;
    }

    /**
     * 判断是否需要用详情页标题纠正当前标题。
     *
     * @param string|null $current 当前标题
     * @param string $candidate 详情页标题
     * @return bool 是否需要更新
     * 无副作用。
     */
    private function shouldUpdateThreadTitle(?string $current, string $candidate): bool
    {
        if ($candidate === '' || $this->isNumericText($candidate)) {
            return false;
        }

        $currentTitle = $current ?? '';
        if ($currentTitle === '' || $this->isNumericText($currentTitle)) {
            return true;
        }

        return $currentTitle !== $candidate;
    }

    /**
     * 判断文本是否为纯数字（避免错误用 tid 覆盖标题）。
     *
     * @param string $text 文本
     * @return bool 是否为纯数字
     * 无副作用。
     */
    private function isNumericText(string $text): bool
    {
        return preg_match('/^\d+$/', $text) === 1;
    }

    /**
     * 提取标题前缀（如 [水]）。
     *
     * @param string $title 标题
     * @return string|null 前缀文本
     * 无副作用。
     */
    private function extractTitlePrefix(string $title): ?string
    {
        if (preg_match('/^\[(.+?)]/', $title, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * 移除 0 楼正文前部的主题标题（避免重复展示）。
     *
     * @param string $html 原始内容 HTML
     * @param string $threadTitle 主题标题
     * @return string 处理后的 HTML
     * 无副作用。
     */
    private function stripLeadingThreadTitle(string $html, string $threadTitle): string
    {
        if ($html === '' || $threadTitle === '') {
            return $html;
        }

        $normalizedTitle = trim(preg_replace('/\\s+/', ' ', $threadTitle) ?? $threadTitle);
        $titleCandidates = [$normalizedTitle];
        $titleWithoutTrailingNumber = preg_replace('/\\s*\\d+\\s*$/u', '', $normalizedTitle);
        if ($titleWithoutTrailingNumber !== null && $titleWithoutTrailingNumber !== $normalizedTitle) {
            $titleCandidates[] = trim($titleWithoutTrailingNumber);
        }

        // 关键规则：0 楼正文不应重复主题标题（标题应仅存 threads.title）
        $patterns = [];
        foreach ($titleCandidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $patterns[] = preg_quote($candidate, '/');
            $patterns[] = preg_quote(htmlspecialchars($candidate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/');
        }
        $patterns = array_values(array_unique($patterns));

        foreach ($patterns as $titlePattern) {
            if ($titlePattern === '') {
                continue;
            }

            $pattern = '/^\s*(?:<br\s*\/?>\s*)*'.$titlePattern.'(?:\s*<br\s*\/?>\s*)*/iu';
            if (preg_match($pattern, $html) === 1) {
                $html = preg_replace($pattern, '', $html, 1) ?? $html;
                break;
            }
        }

        // 再清理一次首部 <br>，避免残留空行
        $html = preg_replace('/^(?:\s*<br\s*\/?>\s*)+/i', '', $html) ?? $html;

        return $html;
    }
}
