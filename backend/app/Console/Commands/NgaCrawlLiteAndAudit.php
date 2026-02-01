<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 负责串联列表抓取与缺楼层审计的调度包装命令。
 */
class NgaCrawlLiteAndAudit extends Command
{
    /**
     * 命令签名。
     *
     * 规则说明：
     * - 默认 fid=7：与历史命令行为保持一致。
     * - 默认 recent-days=3：沿用当前抓取窗口口径。
     * - 默认 audit-delay=300：抓取成功后延迟 5 分钟再审计。
     */
    protected $signature = 'nga:crawl-lite-and-audit
        {--fid=7 : 版面 fid}
        {--recent-days=3 : 抓取最近天数}
        {--audit-delay=300 : 抓取成功后延迟秒数}
        {--trigger-text=scheduler : 触发来源标识}';

    /**
     * 命令说明。
     *
     * @var string
     */
    protected $description = 'Run crawl-lite first, then audit missing floors with repair';

    /**
     * 执行命令入口。
     *
     * @return int 退出码
     * 副作用：执行抓取与审计命令、写入调度日志并阻塞等待延迟时间。
     */
    public function handle(): int
    {
        $runId = (string) Str::uuid();
        // 业务含义：调度任务统一使用 Asia/Shanghai 作为时间口径。
        $timezone = 'Asia/Shanghai';

        $fid = (int) $this->option('fid');
        $recentDays = (int) $this->option('recent-days');
        $auditDelaySeconds = (int) $this->option('audit-delay');
        $triggerText = (string) $this->option('trigger-text');

        // 业务含义：参数非法时直接失败，避免产生不可预期的抓取窗口。
        $hasInvalidFid = $fid <= 0;
        $hasInvalidRecentDays = $recentDays < 0;
        $hasInvalidDelay = $auditDelaySeconds < 0;
        $hasInvalidParams = $hasInvalidFid || $hasInvalidRecentDays || $hasInvalidDelay;

        if ($hasInvalidParams) {
            Log::channel('scheduler')->error('调度参数非法，终止执行', [
                'run_id' => $runId,
                'fid' => $fid,
                'recent_days' => $recentDays,
                'audit_delay_seconds' => $auditDelaySeconds,
            ]);
            $this->error('参数非法，请检查 fid/recent-days/audit-delay');
            return self::FAILURE;
        }

        Log::channel('scheduler')->info('调度任务开始', [
            'run_id' => $runId,
            'timezone' => $timezone,
            'fid' => $fid,
            'recent_days' => $recentDays,
            'audit_delay_seconds' => $auditDelaySeconds,
            'trigger_text' => $triggerText,
            'started_at' => CarbonImmutable::now($timezone)->toDateTimeString(),
        ]);

        $crawlResult = $this->runArtisanCommandWithLog(
            'nga:crawl-lite',
            [
                '--fid' => $fid,
                '--recent-days' => $recentDays,
                '--trigger-text' => $triggerText,
            ],
            'crawl',
            $runId,
            $timezone
        );

        // 业务含义：抓取失败时不进入审计流程，避免在不完整数据上修补。
        $isCrawlSuccess = $crawlResult['exit_code'] === self::SUCCESS;
        if (! $isCrawlSuccess) {
            Log::channel('scheduler')->error('抓取失败，审计流程已跳过', [
                'run_id' => $runId,
                'crawl_exit_code' => $crawlResult['exit_code'],
            ]);
            return self::FAILURE;
        }

        // 业务含义：抓取成功后等待固定延迟，确保源站压力平滑。
        if ($auditDelaySeconds > 0) {
            Log::channel('scheduler')->info('进入审计前延迟等待', [
                'run_id' => $runId,
                'delay_seconds' => $auditDelaySeconds,
                'delay_started_at' => CarbonImmutable::now($timezone)->toDateTimeString(),
            ]);
            sleep($auditDelaySeconds);
        }

        $auditResult = $this->runArtisanCommandWithLog(
            'nga:audit-missing-floors',
            [
                '--repair' => true,
                '--trigger-text' => $triggerText,
            ],
            'audit',
            $runId,
            $timezone
        );

        $isAuditSuccess = $auditResult['exit_code'] === self::SUCCESS;
        Log::channel('scheduler')->info('调度任务结束', [
            'run_id' => $runId,
            'finished_at' => CarbonImmutable::now($timezone)->toDateTimeString(),
            'audit_exit_code' => $auditResult['exit_code'],
        ]);

        return $isAuditSuccess ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 执行 Artisan 命令并写入调度日志。
     *
     * @param string $command Artisan 命令名
     * @param array<string, mixed> $parameters 命令参数
     * @param string $stage 阶段标识
     * @param string $runId 调度批次标识
     * @param string $timezone 时区标识
     * @return array{exit_code:int, output:string, started_at:string, finished_at:string, duration_ms:int} 运行结果摘要
     * 副作用：执行 Artisan 命令并写入调度日志。
     */
    private function runArtisanCommandWithLog(
        string $command,
        array $parameters,
        string $stage,
        string $runId,
        string $timezone
    ): array {
        $startedAt = CarbonImmutable::now($timezone);
        Log::channel('scheduler')->info('调度命令开始', [
            'run_id' => $runId,
            'stage' => $stage,
            'command' => $command,
            'parameters' => $parameters,
            'started_at' => $startedAt->toDateTimeString(),
        ]);

        $outputBuffer = new BufferedOutput();
        $exitCode = (int) Artisan::call($command, $parameters, $outputBuffer);
        $finishedAt = CarbonImmutable::now($timezone);
        $durationMs = $startedAt->diffInMilliseconds($finishedAt);
        $output = $outputBuffer->fetch();

        $logContext = [
            'run_id' => $runId,
            'stage' => $stage,
            'command' => $command,
            'parameters' => $parameters,
            'exit_code' => $exitCode,
            'started_at' => $startedAt->toDateTimeString(),
            'finished_at' => $finishedAt->toDateTimeString(),
            'duration_ms' => $durationMs,
            'output' => $output,
        ];

        // 业务含义：失败时提升日志级别，便于排查与告警。
        if ($exitCode === self::SUCCESS) {
            Log::channel('scheduler')->info('调度命令结束', $logContext);
        } else {
            Log::channel('scheduler')->error('调度命令结束（失败）', $logContext);
        }

        return [
            'exit_code' => $exitCode,
            'output' => $output,
            'started_at' => $startedAt->toDateTimeString(),
            'finished_at' => $finishedAt->toDateTimeString(),
            'duration_ms' => $durationMs,
        ];
    }
}
