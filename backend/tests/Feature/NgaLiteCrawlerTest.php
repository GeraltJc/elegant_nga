<?php

namespace Tests\Feature;

use App\Models\Post;
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

class NgaLiteCrawlerTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame(1, $post->floor_number);
        $this->assertSame('<strong>Hello</strong>', $post->content_html);
    }

    public function test_crawl_is_idempotent(): void
    {
        $crawler = $this->makeCrawler();

        $crawler->crawlForum(7, 5, null, 1);
        $crawler->crawlForum(7, 5, null, 1);

        $this->assertSame(2, Thread::count());
        $this->assertSame(3, Post::count());
    }

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
        $this->assertSame(2, $thread->crawl_cursor_max_floor_number);
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
        $this->assertSame(3, $thread->crawl_cursor_max_floor_number);
        $this->assertSame(9003, $thread->crawl_cursor_max_source_post_id);
        $this->assertTrue($thread->last_crawled_at->gt($firstCrawledAt));
        $this->assertTrue($thread->last_detected_change_at->gt($firstChangeAt));

        CarbonImmutable::setTestNow();
    }

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
     * @param array<int, array<string, mixed>> $threads
     */
    private function makeListPayload(int $fid, array $threads): string
    {
        return json_encode([
            'fid' => $fid,
            'threads' => $threads,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, array<string, mixed>> $posts
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
     * @return array<string, mixed>
     */
    private function makePostPayload(
        int $pid,
        int $floor,
        string $author,
        int $authorId,
        string $postTime,
        string $content
    ): array {
        return [
            'pid' => $pid,
            'floor' => $floor,
            'author' => $author,
            'author_id' => $authorId,
            'post_time' => $postTime,
            'content' => $content,
            'is_deleted' => 0,
            'is_folded' => 0,
            'edited_at' => null,
        ];
    }

    /**
     * @return array<int, array<int, string>>
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
     * @param array<int, string> $listQueue
     * @param array<int, array<int, array<int, string>>> $threadQueues
     */
    public function __construct(array $listQueue, array $threadQueues)
    {
        $this->listQueue = $listQueue;
        $this->threadQueues = $threadQueues;
    }

    public function fetchList(int $fid, int $page = 1): string
    {
        return $this->shiftQueue($this->listQueue, "list:{$fid}:{$page}");
    }

    public function fetchThread(int $tid, int $page = 1): string
    {
        $this->threadCalls[] = ['tid' => $tid, 'page' => $page];

        return $this->shiftThreadQueue($tid, $page);
    }

    private function shiftThreadQueue(int $tid, int $page): string
    {
        if (!isset($this->threadQueues[$tid][$page])) {
            throw new RuntimeException("Thread queue missing: {$tid}/{$page}");
        }

        $queue = &$this->threadQueues[$tid][$page];

        return $this->shiftQueue($queue, "thread:{$tid}:{$page}");
    }

    /**
     * @param array<int, string> $queue
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
