<?php

namespace App\Console\Commands;

use App\Services\Nga\FixtureNgaLiteClient;
use App\Services\Nga\HttpNgaLiteClient;
use App\Services\Nga\NgaLiteCrawler;
use App\Services\Nga\NgaLiteListParser;
use App\Services\Nga\NgaLitePayloadDecoder;
use App\Services\Nga\NgaLiteThreadParser;
use App\Services\Nga\NgaPostContentProcessor;
use App\Services\Nga\ThreadFloorAuditService;
use Illuminate\Console\Command;

/**
 * 缺楼层离线审计与修补命令，用于落表记录缺口与修补结果。
 */
class AuditMissingFloors extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nga:audit-missing-floors
        {--repair : 是否执行修补}
        {--max-post-pages=5 : 修补时单次抓取最大页数}
        {--limit= : 限制审计主题数量}
        {--thread-ids= : 指定 tid 列表（逗号分隔）}
        {--source=http : 数据来源（http/fixture）}
        {--fixtures= : fixture 目录路径}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '离线审计缺楼层并可选修补';

    /**
     * Execute the console command.
     *
     * @return int 退出码
     */
    public function handle(): int
    {
        $repairEnabled = (bool) $this->option('repair');
        $maxPostPages = max(1, (int) $this->option('max-post-pages'));
        $limitOption = $this->option('limit');
        $limit = $limitOption === null ? null : (int) $limitOption;
        $threadIdsOption = trim((string) $this->option('thread-ids'));
        $sourceThreadIds = $this->parseSourceThreadIds($threadIdsOption);

        if ($limit !== null && $limit <= 0) {
            $this->error('limit 必须为正整数或留空');
            return self::FAILURE;
        }

        if ($threadIdsOption !== '' && $sourceThreadIds === []) {
            $this->error('thread-ids 解析失败，请使用逗号分隔的正整数 tid');
            return self::FAILURE;
        }

        $source = (string) $this->option('source');
        $decoder = new NgaLitePayloadDecoder();
        $listParser = new NgaLiteListParser($decoder);
        $threadParser = new NgaLiteThreadParser($decoder);

        // 业务含义：修补场景默认走 HTTP 抓取，fixture 只用于回放场景
        if ($source === 'fixture') {
            $fixtures = (string) $this->option('fixtures');
            $client = new FixtureNgaLiteClient($fixtures !== '' ? $fixtures : base_path('tests/Fixtures/nga'));
        } else {
            $client = new HttpNgaLiteClient(7);
        }

        $contentProcessor = NgaPostContentProcessor::makeDefault();
        $crawler = new NgaLiteCrawler($client, $listParser, $threadParser, $contentProcessor);
        $service = new ThreadFloorAuditService($crawler);

        $run = $service->run($repairEnabled, $maxPostPages, $limit, 'manual', $sourceThreadIds);

        $this->info("Audit Run ID: {$run->id}");
        $this->info('Repair enabled: '.($run->repair_enabled ? 'yes' : 'no'));
        $this->info("Total threads scanned: {$run->total_thread_count}");
        if ($sourceThreadIds !== null && $sourceThreadIds !== []) {
            $this->info('Thread filter: '.implode(',', $sourceThreadIds));
        }
        $this->info("Missing threads: {$run->missing_thread_count}");
        $this->info("Repaired/Partial/Failed: {$run->repaired_thread_count} / {$run->partial_thread_count} / {$run->failed_thread_count}");
        $this->info("Failed by category (http/parse/db/unknown): {$run->failed_http_count} / {$run->failed_parse_count} / {$run->failed_db_count} / {$run->failed_unknown_count}");

        return self::SUCCESS;
    }

    /**
     * 解析指定 tid 列表（逗号分隔）。
     *
     * @param string $raw 输入文本
     * @return array<int, int> tid 数组
     * 无副作用。
     */
    private function parseSourceThreadIds(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $parts = preg_split('/[\\s,]+/', $trimmed) ?: [];
        $ids = [];

        foreach ($parts as $part) {
            $value = trim($part);
            if ($value === '' || !ctype_digit($value)) {
                return [];
            }
            $id = (int) $value;
            if ($id <= 0) {
                return [];
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }
}
