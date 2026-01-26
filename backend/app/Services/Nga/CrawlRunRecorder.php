<?php

namespace App\Services\Nga;

use App\Models\CrawlRun;
use App\Models\CrawlRunThread;
use App\Models\Thread;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 抓取运行审计记录器，用于统一写入 run 与 thread 明细。
 */
class CrawlRunRecorder
{
    private ?CrawlRun $run = null;
    private ?CarbonImmutable $runStartedAt = null;
    private ?CarbonImmutable $runFinishedAt = null;
    private int $threadScannedCount = 0;
    private int $threadChangeDetectedCount = 0;
    private int $threadUpdatedCount = 0;
    private int $httpRequestCount = 0;
    private int $newPostCount = 0;
    private int $updatedPostCount = 0;
    private int $failedThreadCount = 0;

    /**
     * 创建抓取运行记录并初始化统计。
     *
     * @param int $forumId 版块主键
     * @param string $triggerText 触发来源标识
     * @param CarbonImmutable|null $windowStart 统计窗口起
     * @param CarbonImmutable|null $windowEnd 统计窗口止
     * @param CarbonImmutable $startedAt 运行开始时间
     * @return CrawlRun 运行记录模型
     * 副作用：写入 crawl_runs。
     */
    public function startRun(
        int $forumId,
        string $triggerText,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd,
        CarbonImmutable $startedAt
    ): CrawlRun {
        $this->runStartedAt = $startedAt;

        $this->run = CrawlRun::create([
            'forum_id' => $forumId,
            'run_started_at' => $startedAt,
            'run_trigger_text' => $triggerText,
            'date_window_start' => $windowStart?->toDateString(),
            'date_window_end' => $windowEnd?->toDateString(),
            'thread_scanned_count' => 0,
            'thread_change_detected_count' => 0,
            'thread_updated_count' => 0,
            'http_request_count' => 0,
        ]);

        return $this->run;
    }

    /**
     * 标记运行结束并写入汇总统计。
     *
     * @param CarbonImmutable $finishedAt 运行结束时间
     * @return void
     * 副作用：更新 crawl_runs。
     */
    public function finishRun(CarbonImmutable $finishedAt): void
    {
        if (!$this->run instanceof CrawlRun) {
            return;
        }

        if ($this->runFinishedAt !== null) {
            return;
        }

        $this->runFinishedAt = $finishedAt;

        $this->run->fill([
            'run_finished_at' => $finishedAt,
            'thread_scanned_count' => $this->threadScannedCount,
            'thread_change_detected_count' => $this->threadChangeDetectedCount,
            'thread_updated_count' => $this->threadUpdatedCount,
            'http_request_count' => $this->httpRequestCount,
        ]);
        $this->run->save();
    }

    /**
     * 获取运行汇总统计，供命令输出使用。
     *
     * @return array{
     *     run_id:int|null,
     *     run_started_at:string|null,
     *     run_finished_at:string|null,
     *     date_window_start:string|null,
     *     date_window_end:string|null,
     *     thread_scanned_count:int,
     *     thread_change_detected_count:int,
     *     thread_updated_count:int,
     *     http_request_count:int,
     *     new_post_count:int,
     *     updated_post_count:int,
     *     failed_thread_count:int,
     *     duration_ms:int|null
     * }
     * 无副作用。
     */
    public function getSummary(): array
    {
        $durationMs = null;
        if ($this->runStartedAt instanceof CarbonImmutable && $this->runFinishedAt instanceof CarbonImmutable) {
            $durationMs = $this->runFinishedAt->diffInMilliseconds($this->runStartedAt, true);
        }

        return [
            'run_id' => $this->run?->id === null ? null : (int) $this->run->id,
            'run_started_at' => $this->formatDateTime($this->runStartedAt),
            'run_finished_at' => $this->formatDateTime($this->runFinishedAt),
            'date_window_start' => $this->formatDate($this->run?->date_window_start),
            'date_window_end' => $this->formatDate($this->run?->date_window_end),
            'thread_scanned_count' => $this->threadScannedCount,
            'thread_change_detected_count' => $this->threadChangeDetectedCount,
            'thread_updated_count' => $this->threadUpdatedCount,
            'http_request_count' => $this->httpRequestCount,
            'new_post_count' => $this->newPostCount,
            'updated_post_count' => $this->updatedPostCount,
            'failed_thread_count' => $this->failedThreadCount,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * 格式化日期时间为字符串，保持上海时区口径。
     *
     * @param CarbonInterface|null $value 日期时间
     * @return string|null
     * 无副作用。
     */
    private function formatDateTime(?CarbonInterface $value): ?string
    {
        if (!$value instanceof CarbonInterface) {
            return null;
        }

        return $value->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');
    }

    /**
     * 格式化日期为字符串。
     *
     * @param CarbonInterface|null $value 日期
     * @return string|null
     * 无副作用。
     */
    private function formatDate(?CarbonInterface $value): ?string
    {
        if (!$value instanceof CarbonInterface) {
            return null;
        }

        return $value->format('Y-m-d');
    }

    /**
     * 增加扫描主题计数。
     *
     * @return void
     * 无副作用。
     */
    public function increaseThreadScannedCount(): void
    {
        $this->threadScannedCount++;
    }

    /**
     * 增加变化检测命中的主题计数。
     *
     * @return void
     * 无副作用。
     */
    public function increaseThreadChangeDetectedCount(): void
    {
        $this->threadChangeDetectedCount++;
    }

    /**
     * 增加成功更新的主题计数。
     *
     * @return void
     * 无副作用。
     */
    public function increaseThreadUpdatedCount(): void
    {
        $this->threadUpdatedCount++;
    }

    /**
     * 增加 HTTP 请求次数统计。
     *
     * @param int $delta 增量值
     * @return void
     * 无副作用。
     */
    public function increaseHttpRequestCount(int $delta = 1): void
    {
        $this->httpRequestCount += $delta;
    }

    /**
     * 累加新增楼层数。
     *
     * @param int $delta 增量值
     * @return void
     * 无副作用。
     */
    public function increaseNewPostCount(int $delta): void
    {
        $this->newPostCount += $delta;
    }

    /**
     * 累加更新楼层数。
     *
     * @param int $delta 增量值
     * @return void
     * 无副作用。
     */
    public function increaseUpdatedPostCount(int $delta): void
    {
        $this->updatedPostCount += $delta;
    }

    /**
     * 增加失败主题数统计。
     *
     * @return void
     * 无副作用。
     */
    public function increaseFailedThreadCount(): void
    {
        $this->failedThreadCount++;
    }

    /**
     * 创建主题处理明细记录。
     *
     * @param CrawlRun $run 当前运行记录
     * @param Thread $thread 主题模型
     * @param bool $changeDetected 是否因 last_reply_at 变化入队
     * @param CarbonInterface|null $detectedLastReplyAt 检测到的 last_reply_at
     * @param CarbonImmutable $startedAt 主题处理开始时间
     * @return CrawlRunThread 抓取明细模型
     * 副作用：写入 crawl_run_threads。
     */
    public function startThread(
        CrawlRun $run,
        Thread $thread,
        bool $changeDetected,
        ?CarbonInterface $detectedLastReplyAt,
        CarbonImmutable $startedAt
    ): CrawlRunThread {
        return CrawlRunThread::create([
            'crawl_run_id' => $run->id,
            'thread_id' => $thread->id,
            'change_detected_by_last_reply_at' => $changeDetected,
            'detected_last_reply_at' => $detectedLastReplyAt,
            'fetched_page_count' => 0,
            'page_limit_applied' => false,
            'new_post_count' => 0,
            'updated_post_count' => 0,
            'http_error_code' => null,
            'error_summary' => null,
            'started_at' => $startedAt,
            'finished_at' => null,
        ]);
    }

    /**
     * 更新主题处理成功结果。
     *
     * @param CrawlRunThread $runThread 抓取明细
     * @param CarbonImmutable $finishedAt 处理结束时间
     * @param int $fetchedPageCount 实际抓取页数
     * @param bool $pageLimitApplied 是否触发页上限
     * @param int $newPostCount 新增楼层数
     * @param int $updatedPostCount 更新楼层数
     * @return void
     * 副作用：更新 crawl_run_threads。
     */
    public function markThreadSuccess(
        CrawlRunThread $runThread,
        CarbonImmutable $finishedAt,
        int $fetchedPageCount,
        bool $pageLimitApplied,
        int $newPostCount,
        int $updatedPostCount
    ): void {
        $runThread->fill([
            'fetched_page_count' => $fetchedPageCount,
            'page_limit_applied' => $pageLimitApplied,
            'new_post_count' => $newPostCount,
            'updated_post_count' => $updatedPostCount,
            'http_error_code' => null,
            'error_summary' => null,
            'finished_at' => $finishedAt,
        ]);
        $runThread->save();
    }

    /**
     * 更新主题处理失败结果。
     *
     * @param CrawlRunThread $runThread 抓取明细
     * @param CarbonImmutable $finishedAt 处理结束时间
     * @param int|null $httpErrorCode HTTP 状态码
     * @param string $errorSummary 错误摘要
     * @return void
     * 副作用：更新 crawl_run_threads。
     */
    public function markThreadFailure(
        CrawlRunThread $runThread,
        CarbonImmutable $finishedAt,
        ?int $httpErrorCode,
        string $errorSummary
    ): void {
        $runThread->fill([
            'http_error_code' => $httpErrorCode,
            'error_summary' => $errorSummary,
            'finished_at' => $finishedAt,
        ]);
        $runThread->save();
    }
}
