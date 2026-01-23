<?php

namespace App\Services\Nga;

use App\Models\Forum;
use App\Models\Post;
use App\Models\Thread;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class NgaLiteCrawler
{
    private const PAGE_TOTAL_SKIP_LIMIT = 1000;

    public function __construct(
        private readonly NgaLiteClient $client,
        private readonly NgaLiteListParser $listParser,
        private readonly NgaLiteThreadParser $threadParser,
        private readonly NgaPostContentProcessor $contentProcessor
    ) {
    }

    /**
     * @return array{threads:int, posts:int}
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
                continue;
            }

            $startPage = $this->determineThreadStartPage($thread, $lastReplyChanged);
            if ($startPage !== null) {
                $threadResult = $this->crawlThreadSegment($thread, $startPage, $maxPostPages, $now);
                $postsUpserted += $threadResult['posts'];
            }
        }

        return [
            'threads' => $threadsUpserted,
            'posts' => $postsUpserted,
        ];
    }

    /**
     * @return array{thread:int, posts:int}
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

        $result = $this->crawlThreadSegment($thread, 1, $maxPostPages, $now);

        return [
            'thread' => 1,
            'posts' => $result['posts'],
        ];
    }

    /**
     * @return array{posts:int}
     */
    /**
     * @return array{posts:int}
     */
    private function crawlThreadSegment(Thread $thread, int $startPage, int $maxPostPages, CarbonImmutable $now): array
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
                // 游标内的楼层不重复处理
                if ($this->shouldSkipByCursor($floorNumber, $sourcePostId, $maxFloor, $maxPid)) {
                    continue;
                }

                $content = (string) ($postData['content_raw'] ?? '');
                $contentFormat = (string) ($postData['content_format'] ?? 'ubb');
                $contentHtml = $this->contentProcessor->toSafeHtml($content, $contentFormat);
                if ($floorNumber === 0 && $threadTitle !== '') {
                    $contentHtml = $this->stripLeadingThreadTitle($contentHtml, $threadTitle);
                }
                $fingerprint = hash('sha256', $content);

                $post = Post::firstOrNew([
                    'thread_id' => $thread->id,
                    'source_post_id' => $sourcePostId,
                ]);

                $previousFingerprint = $post->exists ? $post->content_fingerprint_sha256 : null;
                $previousDeleted = $post->exists ? (bool) $post->is_deleted_by_source : null;
                $previousFolded = $post->exists ? (bool) $post->is_folded_by_source : null;

                $post->fill([
                    'floor_number' => $floorNumber,
                    'author_name' => $postData['author_name'],
                    'author_source_user_id' => $postData['author_source_user_id'],
                    'post_created_at' => $postData['post_created_at'],
                    'content_html' => $contentHtml,
                    'content_fingerprint_sha256' => $fingerprint,
                    'is_deleted_by_source' => $postData['is_deleted_by_source'],
                    'is_folded_by_source' => $postData['is_folded_by_source'],
                ]);

                if (
                    !$post->exists
                    || $previousFingerprint !== $fingerprint
                    || $previousDeleted !== $post->is_deleted_by_source
                    || $previousFolded !== $post->is_folded_by_source
                ) {
                    $post->content_last_changed_at = $now;
                }

                $post->save();
                $postsUpserted++;

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

    private function defaultListUrl(int $fid): string
    {
        // 访客模式不走 lite=js
        return "https://nga.178.com/thread.php?fid={$fid}&order_by=postdatedesc";
    }

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

    private function isNumericText(string $text): bool
    {
        return preg_match('/^\d+$/', $text) === 1;
    }

    private function extractTitlePrefix(string $title): ?string
    {
        if (preg_match('/^\[(.+?)]/', $title, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

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
