<?php

namespace App\Console\Commands;

use App\Services\Nga\FixtureNgaLiteClient;
use App\Services\Nga\HttpNgaLiteClient;
use App\Services\Nga\NgaLiteCrawler;
use App\Services\Nga\NgaLiteListParser;
use App\Services\Nga\NgaLitePayloadDecoder;
use App\Services\Nga\NgaLiteThreadParser;
use App\Services\Nga\NgaPostContentProcessor;
use Illuminate\Console\Command;

/**
 * 负责抓取指定 tid 的帖子并持久化。
 */
class NgaCrawlThread extends Command
{
    /**
     * 默认 fid。
     *
     * 业务含义：单帖抓取沿用历史命令固定板块，避免意外换源。
     */
    private const DEFAULT_FID = 7;

    /**
     * 命令签名。
     */
    protected $signature = 'nga:crawl-thread
        {tid : 主题 tid}
        {--source=http : 数据来源（http/fixture）}
        {--max-post-pages=5 : 单帖抓取最大页数}
        {--force : 强制抓取（忽略缓存判断）}
        {--fixtures= : fixture 目录路径}';

    /**
     * 命令说明。
     *
     * @var string
     */
    protected $description = 'Crawl single NGA thread by tid and persist';

    /**
     * 执行命令入口。
     *
     * @return int 退出码
     * 副作用：可能发起 HTTP 请求、写入数据库并输出控制台日志。
     */
    public function handle(): int
    {
        $source = (string) $this->option('source');
        $tid = (int) $this->argument('tid');
        $maxPostPages = (int) $this->option('max-post-pages');
        $force = (bool) $this->option('force');

        // 业务含义：tid 必须为正整数，避免误抓空数据。
        if ($tid <= 0) {
            $this->error('tid 必须为正整数');
            return self::FAILURE;
        }

        $decoder = new NgaLitePayloadDecoder();
        $listParser = new NgaLiteListParser($decoder);
        $threadParser = new NgaLiteThreadParser($decoder);

        // 业务含义：fixture 用于回放测试，默认走 HTTP 访客抓取。
        if ($source === 'fixture') {
            $fixtures = (string) $this->option('fixtures');
            $client = new FixtureNgaLiteClient($fixtures !== '' ? $fixtures : base_path('tests/Fixtures/nga'));
        } else {
            $client = new HttpNgaLiteClient(self::DEFAULT_FID);
        }

        $contentProcessor = NgaPostContentProcessor::makeDefault();
        $crawler = new NgaLiteCrawler($client, $listParser, $threadParser, $contentProcessor);
        $result = $crawler->crawlSingleThread($tid, $maxPostPages, $force);

        $runId = $result['run_id'] ?? 'n/a';
        $runWindowStart = $result['date_window_start'] ?? 'n/a';
        $runWindowEnd = $result['date_window_end'] ?? 'n/a';
        $runStartedAt = $result['run_started_at'] ?? 'n/a';
        $runFinishedAt = $result['run_finished_at'] ?? 'n/a';
        $durationMs = $result['duration_ms'] ?? 'n/a';

        $this->info("Run ID: {$runId}");
        $this->info("Run window: {$runWindowStart} ~ {$runWindowEnd}");
        $this->info("Run time: {$runStartedAt} ~ {$runFinishedAt}");
        $this->info("Duration ms: {$durationMs}");
        $this->info("Thread upserted: {$result['thread']}");
        $this->info("Threads scanned/changed/updated/failed: {$result['thread_scanned_count']} / {$result['thread_change_detected_count']} / {$result['thread_updated_count']} / {$result['failed_thread_count']}");
        $this->info("Posts upserted: {$result['posts']}");
        $this->info("Posts new/updated & HTTP requests: {$result['new_post_count']} / {$result['updated_post_count']} / {$result['http_request_count']}");

        return self::SUCCESS;
    }
}
