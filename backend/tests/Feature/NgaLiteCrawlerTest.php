<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Thread;
use App\Services\Nga\FixtureNgaLiteClient;
use App\Services\Nga\NgaLiteClient;
use App\Services\Nga\NgaLiteCrawler;
use App\Services\Nga\NgaLiteListParser;
use App\Services\Nga\NgaLitePayloadDecoder;
use App\Services\Nga\NgaPostContentProcessor;
use App\Services\Nga\NgaLiteThreadParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * 抓取器集成测试，覆盖增量逻辑与历史版本写入。
 */
class NgaLiteCrawlerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证夹具抓取能写入主题与楼层基础信息。
     *
     * @return void
     * 副作用：读写测试数据库。
     */
    public function test_crawl_with_fixtures_persists_threads_and_posts(): void
    {
        $crawler = $this->makeCrawler();

        $result = $crawler->crawlForum(7, 5, null, 1);

        $this->assertSame(2, $result['threads']);
        $this->assertSame(3, $result['posts']);

        $this->assertSame(2, Thread::count());
        $this->assertSame(3, Post::count());

        $thread = Thread::where('source_thread_id', 1001)->firstOrFail();
        $this->assertSame('[water] First thread', $thread->title);
        $this->assertSame('water', $thread->title_prefix_text);
        $this->assertSame('Alice', $thread->author_name);

        $post = Post::where('source_post_id', 9001)->firstOrFail();
        $this->assertSame(0, $post->floor_number);
        $this->assertSame('<strong>Hello</strong>', $post->content_html);
    }

    /**
     * 验证重复抓取不会产生重复记录。
     *
     * @return void
     * 副作用：读写测试数据库。
     */
    public function test_crawl_is_idempotent(): void
    {
        $crawler = $this->makeCrawler();

        $crawler->crawlForum(7, 5, null, 1);
        $crawler->crawlForum(7, 5, null, 1);

        $this->assertSame(2, Thread::count());
        $this->assertSame(3, Post::count());
    }

    /**
     * 验证主题抓取成功后会用详情页口径刷新 threads.reply_count_display。
     *
     * @return void
     * 副作用：读写测试数据库。
     */
    public function test_crawl_updates_thread_reply_count_display_from_thread_detail(): void
    {
        $listPayload = $this->makeListPayload(7, [[
            'tid' => 7101,
            'title' => 'Reply count from detail',
            'author' => 'Alice',
            'author_id' => 501,
            'post_time' => '2026-01-19 10:00:00',
            'last_reply' => '2026-01-19 10:05:00',
            // 列表口径可能不准：这里故意给 0，期望后续被详情页纠正
            'reply_count' => 0,
            'view_count' => 10,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);

        $post1 = $this->makePostPayload(9201, 1, 'Alice', 501, '2026-01-19 10:00:00', 'Hello');
        $post2 = $this->makePostPayload(9202, 2, 'Bob', 502, '2026-01-19 10:05:00', 'Reply 1');
        $threadPayload = $this->makeThreadPayload(7101, 1, 1, [$post1, $post2]);

        $client = new SequenceNgaLiteClient([$listPayload], [
            7101 => [
                1 => [$threadPayload],
            ],
        ]);
        $crawler = $this->makeCrawler($client);

        $crawler->crawlForum(7, 5, null, 1);

        $thread = Thread::where('source_thread_id', 7101)->firstOrFail();
        // 详情页抓到末页后，最大楼层号为 1（不含楼主 0 楼），应刷新展示回复数
        $this->assertSame(1, $thread->reply_count_display);
    }

    /**
     * 验证楼层内容变化会写入历史版本（保存旧内容快照）。
     *
     * @return void
     * 副作用：读写测试数据库。
     */
    public function test_crawl_writes_revision_when_content_changes(): void
    {
        $listFirst = $this->makeListPayload(7, [[
            'tid' => 7001,
            'title' => 'Revision thread',
            'author' => 'Alice',
            'author_id' => 501,
            'post_time' => '2026-01-19 10:00:00',
            'last_reply' => '2026-01-19 10:00:00',
            'reply_count' => 1,
            'view_count' => 10,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);
        $listChanged = $this->makeListPayload(7, [[
            'tid' => 7001,
            'title' => 'Revision thread',
            'author' => 'Alice',
            'author_id' => 501,
            'post_time' => '2026-01-19 10:00:00',
            'last_reply' => '2026-01-19 10:05:00',
            'reply_count' => 1,
            'view_count' => 12,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);

        $postFirst = $this->makePostPayload(9101, 1, 'Alice', 501, '2026-01-19 10:00:00', 'Old content');
        $postSecond = $this->makePostPayload(9101, 1, 'Alice', 501, '2026-01-19 10:00:00', 'New content');

        $threadFirst = $this->makeThreadPayload(7001, 1, 1, [$postFirst]);
        $threadSecond = $this->makeThreadPayload(7001, 1, 1, [$postSecond]);

        $client = new SequenceNgaLiteClient([$listFirst, $listChanged], [
            7001 => [
                1 => [$threadFirst, $threadSecond],
            ],
        ]);
        $crawler = $this->makeCrawler($client);

        $crawler->crawlForum(7, 5, null, 1);
        $post = Post::where('source_post_id', 9101)->firstOrFail();
        $originalContent = $post->content_html;

        $this->assertSame(0, PostRevision::count());

        $crawler->crawlForum(7, 5, null, 1);

        $this->assertSame(1, PostRevision::count());
        $revision = PostRevision::firstOrFail();
        $this->assertSame($originalContent, $revision->content_html);
        $this->assertSame('content_fingerprint_changed', $revision->change_detected_reason);
    }

    /**
     * 验证删除状态变化会写入历史版本。
     *
     * @return void
     * 副作用：读写测试数据库。
     */
    public function test_crawl_writes_revision_when_deleted_flag_changes(): void
    {
        $listFirst = $this->makeListPayload(7, [[
            'tid' => 7002,
            'title' => 'Deleted thread',
            'author' => 'Bob',
            'author_id' => 502,
            'post_time' => '2026-01-19 11:00:00',
            'last_reply' => '2026-01-19 11:00:00',
            'reply_count' => 1,
            'view_count' => 10,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);
        $listChanged = $this->makeListPayload(7, [[
            'tid' => 7002,
            'title' => 'Deleted thread',
            'author' => 'Bob',
            'author_id' => 502,
            'post_time' => '2026-01-19 11:00:00',
            'last_reply' => '2026-01-19 11:10:00',
            'reply_count' => 1,
            'view_count' => 11,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);

        $postFirst = $this->makePostPayload(9201, 1, 'Bob', 502, '2026-01-19 11:00:00', 'Same content');
        $postSecond = $this->makePostPayload(9201, 1, 'Bob', 502, '2026-01-19 11:00:00', 'Same content', 1, 0);

        $threadFirst = $this->makeThreadPayload(7002, 1, 1, [$postFirst]);
        $threadSecond = $this->makeThreadPayload(7002, 1, 1, [$postSecond]);

        $client = new SequenceNgaLiteClient([$listFirst, $listChanged], [
            7002 => [
                1 => [$threadFirst, $threadSecond],
            ],
        ]);
        $crawler = $this->makeCrawler($client);

        $crawler->crawlForum(7, 5, null, 1);
        $this->assertSame(0, PostRevision::count());

        $crawler->crawlForum(7, 5, null, 1);

        $this->assertSame(1, PostRevision::count());
        $revision = PostRevision::firstOrFail();
        $this->assertSame('marked_deleted_by_source', $revision->change_detected_reason);

        $post = Post::where('source_post_id', 9201)->firstOrFail();
        $this->assertTrue($post->is_deleted_by_source);
    }

    /**
     * 验证折叠状态变化会写入历史版本。
     *
     * @return void
     * 副作用：读写测试数据库。
     */
    public function test_crawl_writes_revision_when_folded_flag_changes(): void
    {
        $listFirst = $this->makeListPayload(7, [[
            'tid' => 7003,
            'title' => 'Folded thread',
            'author' => 'Cara',
            'author_id' => 503,
            'post_time' => '2026-01-19 12:00:00',
            'last_reply' => '2026-01-19 12:00:00',
            'reply_count' => 1,
            'view_count' => 10,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);
        $listChanged = $this->makeListPayload(7, [[
            'tid' => 7003,
            'title' => 'Folded thread',
            'author' => 'Cara',
            'author_id' => 503,
            'post_time' => '2026-01-19 12:00:00',
            'last_reply' => '2026-01-19 12:10:00',
            'reply_count' => 1,
            'view_count' => 11,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);

        $postFirst = $this->makePostPayload(9301, 1, 'Cara', 503, '2026-01-19 12:00:00', 'Same content');
        $postSecond = $this->makePostPayload(9301, 1, 'Cara', 503, '2026-01-19 12:00:00', 'Same content', 0, 1);

        $threadFirst = $this->makeThreadPayload(7003, 1, 1, [$postFirst]);
        $threadSecond = $this->makeThreadPayload(7003, 1, 1, [$postSecond]);

        $client = new SequenceNgaLiteClient([$listFirst, $listChanged], [
            7003 => [
                1 => [$threadFirst, $threadSecond],
            ],
        ]);
        $crawler = $this->makeCrawler($client);

        $crawler->crawlForum(7, 5, null, 1);
        $this->assertSame(0, PostRevision::count());

        $crawler->crawlForum(7, 5, null, 1);

        $this->assertSame(1, PostRevision::count());
        $revision = PostRevision::firstOrFail();
        $this->assertSame('marked_folded_by_source', $revision->change_detected_reason);

        $post = Post::where('source_post_id', 9301)->firstOrFail();
        $this->assertTrue($post->is_folded_by_source);
    }

    /**
     * 验证 last_reply_at 变化驱动增量抓取与游标推进。
     *
     * @return void
     * 副作用：读写测试数据库，覆盖时间流转。
     */
    public function test_incremental_crawl_uses_last_reply_at_and_cursor(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-19 12:00:00', 'Asia/Shanghai'));

        $listFirst = $this->makeListPayload(7, [[
            'tid' => 1001,
            'title' => 'First thread',
            'author' => 'Alice',
            'author_id' => 501,
            'post_time' => '2026-01-19 10:00:00',
            'last_reply' => '2026-01-19 11:00:00',
            'reply_count' => 2,
            'view_count' => 10,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);
        $listSame = $this->makeListPayload(7, [[
            'tid' => 1001,
            'title' => 'First thread',
            'author' => 'Alice',
            'author_id' => 501,
            'post_time' => '2026-01-19 10:00:00',
            'last_reply' => '2026-01-19 11:00:00',
            'reply_count' => 2,
            'view_count' => 11,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);
        $listChanged = $this->makeListPayload(7, [[
            'tid' => 1001,
            'title' => 'First thread',
            'author' => 'Alice',
            'author_id' => 501,
            'post_time' => '2026-01-19 10:00:00',
            'last_reply' => '2026-01-19 12:05:00',
            'reply_count' => 3,
            'view_count' => 12,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);

        $post1 = $this->makePostPayload(9001, 1, 'Alice', 501, '2026-01-19 10:00:00', 'First post');
        $post2 = $this->makePostPayload(9002, 2, 'Bob', 502, '2026-01-19 10:05:00', 'Reply 1');
        $post3 = $this->makePostPayload(9003, 3, 'Cara', 503, '2026-01-19 12:05:00', 'Reply 2');

        $threadFirst = $this->makeThreadPayload(1001, 1, 1, [$post1, $post2]);
        $threadUpdated = $this->makeThreadPayload(1001, 1, 1, [$post1, $post2, $post3]);

        $client = new SequenceNgaLiteClient(
            [$listFirst, $listSame, $listChanged],
            [
                1001 => [
                    1 => [$threadFirst, $threadUpdated],
                ],
            ]
        );

        $crawler = $this->makeCrawler($client);

        $result = $crawler->crawlForum(7, 5, null, 1);
        $this->assertSame(1, $result['threads']);
        $this->assertSame(2, $result['posts']);
        $this->assertSame(2, Post::count());
        $thread = Thread::where('source_thread_id', 1001)->firstOrFail();
        $firstCrawledAt = $thread->last_crawled_at;
        $firstChangeAt = $thread->last_detected_change_at;
        $this->assertNotNull($firstCrawledAt);
        $this->assertNotNull($firstChangeAt);
        $this->assertSame(1, $thread->crawl_cursor_max_floor_number);
        $this->assertSame(9002, $thread->crawl_cursor_max_source_post_id);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-19 12:10:00', 'Asia/Shanghai'));
        $result = $crawler->crawlForum(7, 5, null, 1);
        $this->assertSame(1, $result['threads']);
        $this->assertSame(0, $result['posts']);
        $this->assertSame(1, count($client->threadCalls));
        $this->assertSame(2, Post::count());
        $thread->refresh();
        $this->assertTrue($thread->last_crawled_at->eq($firstCrawledAt));
        $this->assertTrue($thread->last_detected_change_at->eq($firstChangeAt));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-19 12:20:00', 'Asia/Shanghai'));
        $result = $crawler->crawlForum(7, 5, null, 1);
        $this->assertSame(1, $result['threads']);
        $this->assertSame(1, $result['posts']);
        $this->assertSame(2, count($client->threadCalls));
        $thread->refresh();
        $this->assertSame(3, Post::count());
        $this->assertSame(2, $thread->crawl_cursor_max_floor_number);
        $this->assertSame(9003, $thread->crawl_cursor_max_source_post_id);
        $this->assertTrue($thread->last_crawled_at->gt($firstCrawledAt));
        $this->assertTrue($thread->last_detected_change_at->gt($firstChangeAt));

        CarbonImmutable::setTestNow();
    }

    /**
     * 验证超出单次页上限时会标记截断并写入游标。
     *
     * @return void
     * 副作用：读写测试数据库。
     */
    public function test_crawl_marks_truncated_when_exceed_page_limit(): void
    {
        $listPayload = $this->makeListPayload(7, [[
            'tid' => 2001,
            'title' => 'Long thread',
            'author' => 'Dana',
            'author_id' => 504,
            'post_time' => '2026-01-19 08:00:00',
            'last_reply' => '2026-01-19 12:00:00',
            'reply_count' => 6,
            'view_count' => 30,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);

        $threadQueues = [
            2001 => [
                1 => [$this->makeThreadPayload(2001, 1, 6, [$this->makePostPayload(8001, 1, 'Dana', 504, '2026-01-19 08:00:00', 'Post 1')])],
                2 => [$this->makeThreadPayload(2001, 2, 6, [$this->makePostPayload(8002, 2, 'Eve', 505, '2026-01-19 08:10:00', 'Post 2')])],
                3 => [$this->makeThreadPayload(2001, 3, 6, [$this->makePostPayload(8003, 3, 'Finn', 506, '2026-01-19 08:20:00', 'Post 3')])],
                4 => [$this->makeThreadPayload(2001, 4, 6, [$this->makePostPayload(8004, 4, 'Gwen', 507, '2026-01-19 08:30:00', 'Post 4')])],
                5 => [$this->makeThreadPayload(2001, 5, 6, [$this->makePostPayload(8005, 5, 'Hank', 508, '2026-01-19 08:40:00', 'Post 5')])],
            ],
        ];

        $client = new SequenceNgaLiteClient([$listPayload], $threadQueues);
        $crawler = $this->makeCrawler($client);

        $result = $crawler->crawlForum(7, 5, null, 1);

        $this->assertSame(1, $result['threads']);
        $this->assertSame(5, $result['posts']);
        $thread = Thread::where('source_thread_id', 2001)->firstOrFail();
        $this->assertTrue($thread->is_truncated_by_page_limit);
        $this->assertSame(5, $thread->truncated_at_page_number);
        $this->assertSame(6, $thread->crawl_backfill_next_page_number);
        $this->assertSame(5, Post::count());
    }

    /**
     * 验证未变化的主题也能跨多次补齐抓取。
     *
     * @return void
     * 副作用：读写测试数据库。
     */
    public function test_backfill_crawls_all_pages_across_multiple_runs_even_without_last_reply_change(): void
    {
        $listPayload = $this->makeListPayload(7, [[
            'tid' => 3001,
            'title' => 'Backfill thread',
            'author' => 'Iris',
            'author_id' => 601,
            'post_time' => '2026-01-19 08:00:00',
            'last_reply' => '2026-01-19 12:00:00',
            'reply_count' => 360,
            'view_count' => 30,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);

        $threadQueues = [
            3001 => $this->makePagedThreadQueues(3001, 12, 7000),
        ];

        $client = new SequenceNgaLiteClient([$listPayload, $listPayload, $listPayload, $listPayload], $threadQueues);
        $crawler = $this->makeCrawler($client);

        $result = $crawler->crawlForum(7, 5, null, 1);
        $this->assertSame(1, $result['threads']);
        $this->assertSame(5, $result['posts']);
        $thread = Thread::where('source_thread_id', 3001)->firstOrFail();
        $this->assertTrue($thread->is_truncated_by_page_limit);
        $this->assertSame(6, $thread->crawl_backfill_next_page_number);
        $this->assertSame(12, $thread->crawl_page_total_last_seen);

        $result = $crawler->crawlForum(7, 5, null, 1);
        $this->assertSame(1, $result['threads']);
        $this->assertSame(5, $result['posts']);
        $thread->refresh();
        $this->assertTrue($thread->is_truncated_by_page_limit);
        $this->assertSame(11, $thread->crawl_backfill_next_page_number);

        $result = $crawler->crawlForum(7, 5, null, 1);
        $this->assertSame(1, $result['threads']);
        $this->assertSame(2, $result['posts']);
        $thread->refresh();
        $this->assertFalse($thread->is_truncated_by_page_limit);
        $this->assertNull($thread->crawl_backfill_next_page_number);
        $this->assertSame(12, Post::count());

        $result = $crawler->crawlForum(7, 5, null, 1);
        $this->assertSame(1, $result['threads']);
        $this->assertSame(0, $result['posts']);
        $this->assertSame(12, count($client->threadCalls));
    }

    /**
     * 验证主题页数超过上限时跳过详情抓取。
     *
     * @return void
     * 副作用：读写测试数据库。
     */
    public function test_skip_crawling_posts_when_page_total_exceeds_1000(): void
    {
        $listPayload = $this->makeListPayload(7, [[
            'tid' => 4001,
            'title' => 'Huge thread',
            'author' => 'Jack',
            'author_id' => 701,
            'post_time' => '2026-01-19 08:00:00',
            'last_reply' => '2026-01-19 12:00:00',
            'reply_count' => 999999,
            'view_count' => 30,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);

        $hugePayload = $this->makeThreadPayload(4001, 1, 1001, [
            $this->makePostPayload(90001, 1, 'Jack', 701, '2026-01-19 08:00:00', 'Post 1'),
        ]);

        $client = new SequenceNgaLiteClient([$listPayload], [
            4001 => [
                1 => [$hugePayload],
            ],
        ]);
        $crawler = $this->makeCrawler($client);

        $result = $crawler->crawlForum(7, 5, null, 1);
        $this->assertSame(1, $result['threads']);
        $this->assertSame(0, $result['posts']);

        $thread = Thread::where('source_thread_id', 4001)->firstOrFail();
        $this->assertTrue($thread->is_skipped_by_page_total_limit);
        $this->assertNotNull($thread->skipped_by_page_total_limit_at);
        $this->assertSame(1001, $thread->crawl_page_total_last_seen);
        $this->assertSame(0, Post::count());
    }

    /**
     * 验证 last_reply_at 变化时能继续补齐后续分页直至完成。
     *
     * @return void
     * 副作用：读写测试数据库。
     */
    public function test_incremental_crawl_backfills_new_pages_until_complete_when_last_reply_changes(): void
    {
        $listOld = $this->makeListPayload(7, [[
            'tid' => 5001,
            'title' => 'Growing thread',
            'author' => 'Kira',
            'author_id' => 801,
            'post_time' => '2026-01-19 08:00:00',
            'last_reply' => '2026-01-19 12:00:00',
            'reply_count' => 360,
            'view_count' => 30,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);

        $listNew = $this->makeListPayload(7, [[
            'tid' => 5001,
            'title' => 'Growing thread',
            'author' => 'Kira',
            'author_id' => 801,
            'post_time' => '2026-01-19 08:00:00',
            'last_reply' => '2026-01-20 00:00:00',
            'reply_count' => 999,
            'view_count' => 30,
            'is_pinned' => 0,
            'is_digest' => 0,
        ]]);

        $threadQueues = [
            5001 => [],
        ];

        for ($page = 1; $page <= 11; $page++) {
            $threadQueues[5001][$page] = [
                $this->makeThreadPayload(5001, $page, 12, [
                    $this->makePostPayload(8000 + $page, $page, 'User'.$page, 2000 + $page, '2026-01-19 08:00:00', 'P'.$page),
                ]),
            ];
        }

        $threadQueues[5001][12] = [
            $this->makeThreadPayload(5001, 12, 12, [
                $this->makePostPayload(8012, 12, 'User12', 2012, '2026-01-19 08:00:00', 'P12'),
            ]),
            $this->makeThreadPayload(5001, 12, 24, [
                $this->makePostPayload(9012, 12, 'User12', 2012, '2026-01-19 08:00:00', 'P12'),
            ]),
        ];

        for ($page = 13; $page <= 24; $page++) {
            $threadQueues[5001][$page] = [
                $this->makeThreadPayload(5001, $page, 24, [
                    $this->makePostPayload(9000 + $page, $page, 'User'.$page, 2000 + $page, '2026-01-19 08:00:00', 'P'.$page),
                ]),
            ];
        }

        $client = new SequenceNgaLiteClient(
            [$listOld, $listOld, $listOld, $listNew, $listNew, $listNew],
            $threadQueues
        );
        $crawler = $this->makeCrawler($client);

        $crawler->crawlForum(7, 5, null, 1);
        $crawler->crawlForum(7, 5, null, 1);
        $crawler->crawlForum(7, 5, null, 1);
        $this->assertSame(12, Post::count());

        $crawler->crawlForum(7, 5, null, 1);
        $thread = Thread::where('source_thread_id', 5001)->firstOrFail();
        $this->assertTrue($thread->is_truncated_by_page_limit);
        $this->assertSame(17, $thread->crawl_backfill_next_page_number);
        $this->assertSame(24, $thread->crawl_page_total_last_seen);

        $crawler->crawlForum(7, 5, null, 1);
        $crawler->crawlForum(7, 5, null, 1);

        $thread->refresh();
        $this->assertFalse($thread->is_truncated_by_page_limit);
        $this->assertNull($thread->crawl_backfill_next_page_number);
        $this->assertSame(24, Post::count());
        $this->assertSame(25, count($client->threadCalls));
    }

    /**
     * 构造抓取器实例并注入测试用客户端。
     *
     * @param NgaLiteClient|null $client 自定义客户端（可选）
     * @return NgaLiteCrawler
     * 无副作用。
     */
    private function makeCrawler(?NgaLiteClient $client = null): NgaLiteCrawler
    {
        $fixturePath = base_path('tests/Fixtures/nga');
        $client = $client ?? new FixtureNgaLiteClient($fixturePath);
        $decoder = new NgaLitePayloadDecoder();
        $listParser = new NgaLiteListParser($decoder);
        $threadParser = new NgaLiteThreadParser($decoder);
        $contentProcessor = NgaPostContentProcessor::makeDefault();

        return new NgaLiteCrawler($client, $listParser, $threadParser, $contentProcessor);
    }

    /**
     * 构造列表接口的 JSON 夹具。
     *
     * @param int $fid 版块 fid
     * @param array<int, array<string, mixed>> $threads 主题列表数据
     * @return string JSON 字符串
     * 无副作用。
     */
    private function makeListPayload(int $fid, array $threads): string
    {
        return json_encode([
            'fid' => $fid,
            'threads' => $threads,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * 构造详情接口的 JSON 夹具。
     *
     * @param int $tid 主题 tid
     * @param int $page 当前页码
     * @param int $pageTotal 总页数
     * @param array<int, array<string, mixed>> $posts 楼层数组
     * @return string JSON 字符串
     * 无副作用。
     */
    private function makeThreadPayload(int $tid, int $page, int $pageTotal, array $posts): string
    {
        return json_encode([
            'tid' => $tid,
            'page' => $page,
            'page_total' => $pageTotal,
            'posts' => $posts,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * 构造楼层数据数组。
     *
     * @param int $pid pid
     * @param int $floor 楼层号（测试输入按 1 基）
     * @param string $author 作者名
     * @param int $authorId 作者 uid
     * @param string $postTime 发帖时间
     * @param string $content 原始内容
     * @param int $isDeleted 删除标记（0/1）
     * @param int $isFolded 折叠标记（0/1）
     * @param string|null $editedAt 来源编辑时间
     * @return array<string, mixed>
     * 无副作用。
     */
    private function makePostPayload(
        int $pid,
        int $floor,
        string $author,
        int $authorId,
        string $postTime,
        string $content,
        int $isDeleted = 0,
        int $isFolded = 0,
        ?string $editedAt = null
    ): array {
        return [
            'pid' => $pid,
            'floor' => $floor,
            'author' => $author,
            'author_id' => $authorId,
            'post_time' => $postTime,
            'content' => $content,
            'is_deleted' => $isDeleted,
            'is_folded' => $isFolded,
            'edited_at' => $editedAt,
        ];
    }

    /**
     * 构造分页队列夹具，模拟多页主题抓取。
     *
     * @param int $tid 主题 tid
     * @param int $pageTotal 总页数
     * @param int $pidBase pid 基数
     * @return array<int, array<int, string>>
     * 无副作用。
     */
    private function makePagedThreadQueues(int $tid, int $pageTotal, int $pidBase): array
    {
        $queues = [];
        for ($page = 1; $page <= $pageTotal; $page++) {
            $queues[$page] = [
                $this->makeThreadPayload($tid, $page, $pageTotal, [
                    $this->makePostPayload($pidBase + $page, $page, 'User'.$page, 1000 + $page, '2026-01-19 08:00:00', 'P'.$page),
                ]),
            ];
        }

        return $queues;
    }
}

/**
 * 顺序队列客户端，用于按测试脚本顺序返回固定数据。
 */
class SequenceNgaLiteClient implements NgaLiteClient
{
    /**
     * @var array<int, string>
     */
    private array $listQueue;

    /**
     * @var array<int, array<int, array<int, string>>>
     */
    private array $threadQueues;

    /**
     * @var array<int, array{tid:int, page:int}>
     */
    public array $threadCalls = [];

    /**
     * 构造顺序队列客户端。
     *
     * @param array<int, string> $listQueue 列表返回队列
     * @param array<int, array<int, array<int, string>>> $threadQueues 主题详情返回队列
     * 无副作用。
     */
    public function __construct(array $listQueue, array $threadQueues)
    {
        $this->listQueue = $listQueue;
        $this->threadQueues = $threadQueues;
    }

    /**
     * 返回列表页数据并从队列出队。
     *
     * @param int $fid 版块 fid
     * @param int $page 页码
     * @return string JSON 负载
     * 副作用：消耗队列元素。
     */
    public function fetchList(int $fid, int $page = 1): string
    {
        return $this->shiftQueue($this->listQueue, "list:{$fid}:{$page}");
    }

    /**
     * 返回主题详情数据并从队列出队。
     *
     * @param int $tid 主题 tid
     * @param int $page 页码
     * @return string JSON 负载
     * 副作用：记录调用轨迹并消耗队列元素。
     */
    public function fetchThread(int $tid, int $page = 1): string
    {
        $this->threadCalls[] = ['tid' => $tid, 'page' => $page];

        return $this->shiftThreadQueue($tid, $page);
    }

    /**
     * 从指定主题页码队列中取出一个返回值。
     *
     * @param int $tid 主题 tid
     * @param int $page 页码
     * @return string JSON 负载
     * @throws RuntimeException 队列缺失时抛出
     * 副作用：消耗队列元素。
     */
    private function shiftThreadQueue(int $tid, int $page): string
    {
        if (!isset($this->threadQueues[$tid][$page])) {
            throw new RuntimeException("Thread queue missing: {$tid}/{$page}");
        }

        $queue = &$this->threadQueues[$tid][$page];

        return $this->shiftQueue($queue, "thread:{$tid}:{$page}");
    }

    /**
     * 通用出队方法。
     *
     * @param array<int, string> $queue 队列引用
     * @param string $label 队列标签
     * @return string 队列值
     * @throws RuntimeException 队列为空或值非法时抛出
     * 副作用：消耗队列元素。
     */
    private function shiftQueue(array &$queue, string $label): string
    {
        if ($queue === []) {
            throw new RuntimeException("Queue empty: {$label}");
        }

        $value = array_shift($queue);
        if (!is_string($value)) {
            throw new RuntimeException("Queue value invalid: {$label}");
        }

        return $value;
    }
}
