<?php

namespace App\Console\Commands;

use App\Services\Nga\FixtureNgaLiteClient;
use App\Services\Nga\HttpNgaLiteClient;
use App\Services\Nga\NgaLiteCrawler;
use App\Services\Nga\NgaLiteListParser;
use App\Services\Nga\NgaLitePayloadDecoder;
use App\Services\Nga\NgaLiteThreadParser;
use App\Services\Nga\NgaPostContentProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 负责执行 NGA 版面列表抓取并持久化线程/帖子。
 */
class NgaCrawlLite extends Command
{
    /**
     * 命令签名。
     *
     * 规则说明：
     * - 默认 fid=7：与历史命令行为保持一致，避免误抓取。
     */
    protected $signature = 'nga:crawl-lite
        {--source=http : 数据来源（http/fixture）}
        {--fid=7 : 版面 fid}
        {--list-page=1 : 起始列表页码}
        {--max-post-pages=5 : 单帖抓取最大页数}
        {--recent-days=3 : 抓取最近天数}
        {--start-at= : 时间窗口开始（示例：2026-01-23 17:48:00）}
        {--end-at= : 时间窗口结束（示例：2026-01-23 17:50:00）}
        {--trigger-text=manual : 触发来源标识}
        {--fixtures= : fixture 目录路径}';

    /**
     * 命令说明。
     *
     * @var string
     */
    protected $description = 'Crawl NGA HTML list/detail as guest and persist threads/posts';

    /**
     * 执行命令入口。
     *
     * @return int 退出码
     * 副作用：可能发起 HTTP 请求、写入数据库并输出控制台日志。
     */
    public function handle(): int
    {
        $source = (string) $this->option('source');
        $fid = (int) $this->option('fid');
        $listPage = (int) $this->option('list-page');
        $maxPostPages = (int) $this->option('max-post-pages');
        $recentDaysOption = $this->option('recent-days');
        // 业务含义：当参数缺失时保持 null，交由爬虫决定默认窗口。
        $recentDays = $recentDaysOption === null ? null : (int) $recentDaysOption;
        $startAt = (string) $this->option('start-at');
        $endAt = (string) $this->option('end-at');
        $triggerText = (string) $this->option('trigger-text');
        $windowStart = null;
        $windowEnd = null;

        // 业务含义：仅在传入 start-at 时解析开始时间。
        if ($startAt !== '') {
            try {
                // 业务含义：统一以 Asia/Shanghai 解析输入时间，方便与业务时区对齐。
                $windowStart = CarbonImmutable::parse($startAt, 'Asia/Shanghai');
            } catch (\Throwable) {
                $this->error('start-at 格式不合法，示例：2026-01-23 17:48:00');
                return self::FAILURE;
            }
        }

        // 业务含义：仅在传入 end-at 时解析结束时间。
        if ($endAt !== '') {
            try {
                // 业务含义：统一以 Asia/Shanghai 解析输入时间，方便与业务时区对齐。
                $windowEnd = CarbonImmutable::parse($endAt, 'Asia/Shanghai');
            } catch (\Throwable) {
                $this->error('end-at 格式不合法，示例：2026-01-23 17:50:00');
                return self::FAILURE;
            }
        }

        // 业务含义：时间窗口必须满足 start-at <= end-at。
        $hasInvalidWindow = $windowStart !== null
            && $windowEnd !== null
            && $windowStart->gt($windowEnd);

        if ($hasInvalidWindow) {
            $this->error('start-at 不能晚于 end-at');
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
            $client = new HttpNgaLiteClient($fid);
        }

        $contentProcessor = NgaPostContentProcessor::makeDefault();
        $crawler = new NgaLiteCrawler($client, $listParser, $threadParser, $contentProcessor);
        $result = $crawler->crawlForum($fid, $maxPostPages, $recentDays, $listPage, $windowStart, $windowEnd, $triggerText);

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
        $this->info("Threads upserted: {$result['threads']}");
        $this->info("Threads scanned/changed/updated/failed: {$result['thread_scanned_count']} / {$result['thread_change_detected_count']} / {$result['thread_updated_count']} / {$result['failed_thread_count']}");
        $this->info("Posts upserted: {$result['posts']}");
        $this->info("Posts new/updated & HTTP requests: {$result['new_post_count']} / {$result['updated_post_count']} / {$result['http_request_count']}");

        return self::SUCCESS;
    }
}
