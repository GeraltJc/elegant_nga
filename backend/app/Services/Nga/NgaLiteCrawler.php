<?php

namespace App\Services\Nga;

use App\Models\Forum;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Thread;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 轻量抓取器：负责列表扫描与主题详情抓取的入库流程。
 */
class NgaLiteCrawler
{
    private const PAGE_TOTAL_SKIP_LIMIT = 1000;
    private const CHANGE_REASON_CONTENT = 'content_fingerprint_changed';
    private const CHANGE_REASON_DELETED = 'marked_deleted_by_source';
    private const CHANGE_REASON_FOLDED = 'marked_folded_by_source';

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
     * @return array{threads:int, posts:int} 写入数量统计
     * 副作用：写入 forums/threads/posts 等数据。
     */
    public function crawlForum(
        int $fid,
        int $maxPostPages = 5,
        ?int $recentDays = 3,
        int $listPage = 1,
        ?CarbonImmutable $windowStart = null,
        ?CarbonImmutable $windowEnd = null
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

        $threadsData = $this->listParser->parse($this->client->fetchList($fid, $listPage));

        $threadsUpserted = 0;
        $postsUpserted = 0;

        foreach ($threadsData as $threadData) {
            $sourceThreadId = (int) ($threadData['source_thread_id'] ?? 0);
            if ($sourceThreadId <= 0) {
                continue;
            }

            $createdAt = $threadData['thread_created_at'];
            if ($createdAt instanceof CarbonImmutable) {
                // 业务规则：仅抓取自然日窗口内的主题
                if ($windowStart && $createdAt->lt($windowStart)) {
                    continue;
                }
                if ($windowEnd && $createdAt->gt($windowEnd)) {
                    continue;
                }
            }

            $thread = Thread::firstOrNew([
                'forum_id' => $forum->id,
                'source_thread_id' => $sourceThreadId,
            ]);

            if (!$thread->exists) {
                $thread->first_seen_on_list_page_number = $listPage;
            }

            $lastReplyAt = $threadData['last_reply_at'];
            // 以 last_reply_at 变化作为增量抓取开关
            $lastReplyChanged = !$thread->exists || $this->hasLastReplyChanged($thread->last_reply_at, $lastReplyAt);
            if ($lastReplyChanged) {
                $thread->last_detected_change_at = $now;
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
                'last_seen_on_list_page_number' => $listPage,
            ]);

            $thread->save();
            $threadsUpserted++;

            // 兼容旧数据：如果之前因页上限被截断但没有“分段补齐游标”，先补齐游标
            $this->bootstrapBackfillCursorIfNeeded($thread, $maxPostPages);

            if ($thread->is_skipped_by_page_total_limit) {
                // 业务规则：超页数上限的主题不抓详情，只更新列表字段
                continue;
            }

            $startPage = $this->determineThreadStartPage($thread, $lastReplyChanged);
            if ($startPage !== null) {
                $threadResult = $this->crawlThreadSegment($thread, $startPage, $maxPostPages, $now, $lastReplyChanged);
                $postsUpserted += $threadResult['posts'];
            }
        }

        return [
            'threads' => $threadsUpserted,
            'posts' => $postsUpserted,
        ];
    }

    /**
     * 抓取单个主题并写入楼层数据（支持强制重抓）。
     *
     * @param int $tid 主题 tid
     * @param int $maxPostPages 单次抓取最大页数
     * @param bool $force 是否清空游标后从第一页重抓
     * @return array{thread:int, posts:int} 写入数量统计
     * 副作用：写入 threads/posts 并更新抓取游标。
     */
    public function crawlSingleThread(int $tid, int $maxPostPages = 5, bool $force = false): array
    {
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

        if (!$force && $thread->is_skipped_by_page_total_limit) {
            return ['thread' => 0, 'posts' => 0];
        }

        $result = $this->crawlThreadSegment($thread, 1, $maxPostPages, $now, true);

        return [
            'thread' => 1,
            'posts' => $result['posts'],
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
     * @return array{posts:int} 写入楼层数
     * 副作用：写入 posts 并更新 threads 的抓取游标。
     */
    private function crawlThreadSegment(
        Thread $thread,
        int $startPage,
        int $maxPostPages,
        CarbonImmutable $now,
        bool $shouldCheckExistingPosts
    ): array
    {
        $page = max(1, $startPage);
        $pageTotal = 1;
        $postsUpserted = 0;
        $maxFloor = $thread->crawl_cursor_max_floor_number;
        $maxPid = $thread->crawl_cursor_max_source_post_id;
        $endPageFetched = null;

        $pageLimitEnd = $page + max(1, $maxPostPages) - 1;

        while ($page <= $pageLimitEnd) {
            $pageData = $this->threadParser->parse($this->client->fetchThread($thread->source_thread_id, $page));
            $pageTotal = max($pageTotal, (int) $pageData['page_total']);

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
                $thread->crawl_backfill_next_page_number = null;
                $thread->is_truncated_by_page_limit = true;
                $thread->truncated_at_page_number = null;
                $thread->save();

                return ['posts' => 0];
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
                        $changeEvaluation['reasons']
                    );
                }

                if ($changeEvaluation['has_any_change']) {
                    // 业务规则：新增或变更都要刷新“内容最后变化时间”
                    $post->content_last_changed_at = $now;
                }

                $post->save();
                if ($changeEvaluation['has_any_change']) {
                    $postsUpserted++;
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
        ]);
        $thread->save();

        return ['posts' => $postsUpserted];
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
     * 副作用：写入 post_revisions。
     */
    private function recordPostRevision(
        Post $post,
        array $previousSnapshot,
        CarbonImmutable $now,
        ?CarbonInterface $sourceEditedAt,
        array $reasons
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
            // 业务规则：抓取审计明细尚未接入，先留空
            'crawl_run_thread_id' => null,
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
