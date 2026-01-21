<?php

namespace App\Services\Nga;

use App\Models\Forum;
use App\Models\Post;
use App\Models\Thread;
use Carbon\CarbonImmutable;

class NgaLiteCrawler
{
    public function __construct(
        private readonly NgaLiteClient $client,
        private readonly NgaLiteListParser $listParser,
        private readonly NgaLiteThreadParser $threadParser
    ) {
    }

    /**
     * @return array{threads:int, posts:int}
     */
    public function crawlForum(int $fid, int $maxPostPages = 5, ?int $recentDays = 3, int $listPage = 1): array
    {
        $now = CarbonImmutable::now('Asia/Shanghai');
        $windowStart = $recentDays ? $now->startOfDay()->subDays($recentDays - 1) : null;
        $windowEnd = $now->endOfDay();

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
            if ($windowStart && $createdAt instanceof CarbonImmutable) {
                if ($createdAt->lt($windowStart) || $createdAt->gt($windowEnd)) {
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

            $thread->fill([
                'title' => $threadData['title'],
                'title_prefix_text' => $threadData['title_prefix_text'],
                'author_name' => $threadData['author_name'],
                'author_source_user_id' => $threadData['author_source_user_id'],
                'thread_created_at' => $createdAt,
                'last_reply_at' => $threadData['last_reply_at'],
                'reply_count_display' => $threadData['reply_count_display'],
                'view_count_display' => $threadData['view_count_display'],
                'is_pinned' => $threadData['is_pinned'],
                'is_digest' => $threadData['is_digest'],
                'last_seen_on_list_page_number' => $listPage,
            ]);

            $thread->save();
            $threadsUpserted++;

            $threadResult = $this->crawlThread($thread, $maxPostPages, $now);
            $postsUpserted += $threadResult['posts'];
        }

        return [
            'threads' => $threadsUpserted,
            'posts' => $postsUpserted,
        ];
    }

    /**
     * @return array{posts:int}
     */
    private function crawlThread(Thread $thread, int $maxPostPages, CarbonImmutable $now): array
    {
        $page = 1;
        $pageTotal = 1;
        $postsUpserted = 0;
        $maxFloor = $thread->crawl_cursor_max_floor_number ?? 0;
        $maxPid = $thread->crawl_cursor_max_source_post_id ?? 0;

        do {
            $pageData = $this->threadParser->parse(
                $this->client->fetchThread($thread->source_thread_id, $page)
            );

            $pageTotal = max($pageTotal, (int) $pageData['page_total']);

            foreach ($pageData['posts'] as $postData) {
                $sourcePostId = (int) ($postData['source_post_id'] ?? 0);
                $floorNumber = (int) ($postData['floor_number'] ?? 0);
                if ($floorNumber <= 0) {
                    continue;
                }
                if ($sourcePostId <= 0) {
                    $sourcePostId = $floorNumber;
                }

                $content = (string) ($postData['content_raw'] ?? '');
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
                    'content_html' => $content,
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

                $maxFloor = max($maxFloor, $floorNumber);
                $maxPid = max($maxPid, $sourcePostId);
            }

            $page++;
        } while ($page <= $pageTotal && $page <= $maxPostPages);

        $thread->fill([
            'last_crawled_at' => $now,
            'crawl_cursor_max_floor_number' => $maxFloor ?: null,
            'crawl_cursor_max_source_post_id' => $maxPid ?: null,
            'is_truncated_by_page_limit' => $pageTotal > $maxPostPages,
            'truncated_at_page_number' => $pageTotal > $maxPostPages ? $maxPostPages : null,
        ]);
        $thread->save();

        return ['posts' => $postsUpserted];
    }

    private function defaultListUrl(int $fid): string
    {
        // 访客模式不走 lite=js
        return "https://nga.178.com/thread.php?fid={$fid}";
    }
}
