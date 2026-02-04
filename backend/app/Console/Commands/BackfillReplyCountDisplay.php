<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 回填主题回复数，保证 reply_count_display 不低于已抓楼层号。
 */
class BackfillReplyCountDisplay extends Command
{
    /**
     * 命令签名。
     */
    protected $signature = 'nga:backfill-reply-count-display
        {--dry-run : 仅统计不写入}';

    /**
     * 命令说明。
     *
     * @var string
     */
    protected $description = 'Backfill reply_count_display by crawl_cursor_max_floor_number';

    /**
     * 执行命令入口。
     *
     * @return int 退出码
     * 副作用：写入 threads.reply_count_display（仅在非 dry-run）。
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $baseQuery = DB::table('threads')
            ->whereNotNull('crawl_cursor_max_floor_number')
            ->whereColumn('reply_count_display', '<', 'crawl_cursor_max_floor_number');

        $targetCount = (int) $baseQuery->count();

        if ($dryRun) {
            $this->info("将更新的主题数：{$targetCount}");
            return self::SUCCESS;
        }

        // 业务规则：仅回填“已抓楼层更大”的主题，保持回复数只增不减。
        $affected = DB::affectingStatement(
            "UPDATE threads
            SET reply_count_display = GREATEST(reply_count_display, IFNULL(crawl_cursor_max_floor_number, 0))
            WHERE crawl_cursor_max_floor_number IS NOT NULL
              AND reply_count_display < crawl_cursor_max_floor_number"
        );

        $this->info("已更新主题数：{$affected}");
        $this->info("满足条件主题数：{$targetCount}");

        return self::SUCCESS;
    }
}
