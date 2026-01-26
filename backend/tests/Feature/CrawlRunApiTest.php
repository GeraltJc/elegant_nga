<?php

namespace Tests\Feature;

use App\Models\CrawlRun;
use App\Models\CrawlRunThread;
use App\Models\Forum;
use App\Models\Thread;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 抓取运行报表接口测试，验证列表与明细查询能力。
 */
class CrawlRunApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 测试运行列表接口返回默认分页与核心字段。
     *
     * @return void
     */
    public function test_index_returns_paginated_runs(): void
    {
        [$forum] = $this->createForumAndThread();

        $run = CrawlRun::create([
            'forum_id' => $forum->id,
            'run_started_at' => CarbonImmutable::now('Asia/Shanghai'),
            'run_trigger_text' => 'manual',
            'thread_scanned_count' => 1,
            'thread_change_detected_count' => 1,
            'thread_updated_count' => 1,
            'http_request_count' => 3,
        ]);

        $response = $this->getJson('/api/crawl-runs');

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('data.0.id', $run->id);
    }

    /**
     * 测试运行详情接口返回统计汇总。
     *
     * @return void
     */
    public function test_show_returns_run_summary(): void
    {
        [$forum, $thread] = $this->createForumAndThread();
        $startedAt = CarbonImmutable::now('Asia/Shanghai')->subSeconds(5);
        $finishedAt = $startedAt->addSeconds(5);

        $run = CrawlRun::create([
            'forum_id' => $forum->id,
            'run_started_at' => $startedAt,
            'run_finished_at' => $finishedAt,
            'run_trigger_text' => 'manual',
            'thread_scanned_count' => 2,
            'thread_change_detected_count' => 1,
            'thread_updated_count' => 1,
            'http_request_count' => 4,
        ]);

        CrawlRunThread::create([
            'crawl_run_id' => $run->id,
            'thread_id' => $thread->id,
            'change_detected_by_last_reply_at' => true,
            'detected_last_reply_at' => $startedAt,
            'fetched_page_count' => 1,
            'page_limit_applied' => false,
            'new_post_count' => 2,
            'updated_post_count' => 1,
            'http_error_code' => null,
            'error_summary' => null,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        $failedThread = Thread::create([
            'forum_id' => $forum->id,
            'source_thread_id' => 9999,
            'title' => 'failed-thread',
            'author_name' => 'tester',
            'thread_created_at' => $startedAt,
        ]);

        CrawlRunThread::create([
            'crawl_run_id' => $run->id,
            'thread_id' => $failedThread->id,
            'change_detected_by_last_reply_at' => false,
            'detected_last_reply_at' => null,
            'fetched_page_count' => 0,
            'page_limit_applied' => false,
            'new_post_count' => 0,
            'updated_post_count' => 0,
            'http_error_code' => 429,
            'error_summary' => 'http_429',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        $response = $this->getJson("/api/crawl-runs/{$run->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.new_post_count_total', 2)
            ->assertJsonPath('data.updated_post_count_total', 1)
            ->assertJsonPath('data.failed_thread_count', 1)
            ->assertJsonPath('data.duration_ms', 5000);
    }

    /**
     * 测试明细接口支持失败过滤。
     *
     * @return void
     */
    public function test_threads_endpoint_filters_failed_threads(): void
    {
        [$forum, $thread] = $this->createForumAndThread();
        $startedAt = CarbonImmutable::now('Asia/Shanghai')->subSeconds(1);
        $run = CrawlRun::create([
            'forum_id' => $forum->id,
            'run_started_at' => $startedAt,
            'run_trigger_text' => 'manual',
        ]);

        CrawlRunThread::create([
            'crawl_run_id' => $run->id,
            'thread_id' => $thread->id,
            'change_detected_by_last_reply_at' => true,
            'detected_last_reply_at' => $startedAt,
            'fetched_page_count' => 1,
            'page_limit_applied' => false,
            'new_post_count' => 1,
            'updated_post_count' => 0,
            'http_error_code' => null,
            'error_summary' => null,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->addSecond(),
        ]);

        $failedThread = Thread::create([
            'forum_id' => $forum->id,
            'source_thread_id' => 8888,
            'title' => 'failed-thread',
            'author_name' => 'tester',
            'thread_created_at' => $startedAt,
        ]);

        CrawlRunThread::create([
            'crawl_run_id' => $run->id,
            'thread_id' => $failedThread->id,
            'change_detected_by_last_reply_at' => false,
            'detected_last_reply_at' => null,
            'fetched_page_count' => 0,
            'page_limit_applied' => false,
            'new_post_count' => 0,
            'updated_post_count' => 0,
            'http_error_code' => 500,
            'error_summary' => 'http_5xx',
            'started_at' => $startedAt,
            'finished_at' => $startedAt->addSeconds(2),
        ]);

        $response = $this->getJson("/api/crawl-runs/{$run->id}/threads?only_failed=1");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.error_summary', 'http_5xx');
    }

    /**
     * 构造基础版块与主题数据。
     *
     * @return array{0:Forum, 1:Thread}
     */
    private function createForumAndThread(): array
    {
        $forum = Forum::create([
            'source_forum_id' => 7,
            'forum_name' => 'NGA',
            'list_url' => 'https://nga.178.com/thread.php?fid=7',
            'crawl_page_limit' => 5,
            'request_rate_limit_per_sec' => 1.00,
        ]);

        $thread = Thread::create([
            'forum_id' => $forum->id,
            'source_thread_id' => 12345,
            'title' => 'test-thread',
            'author_name' => 'tester',
            'thread_created_at' => CarbonImmutable::now('Asia/Shanghai'),
        ]);

        return [$forum, $thread];
    }
}
