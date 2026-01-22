<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table): void {
            $table->unsignedInteger('crawl_page_total_last_seen')
                ->nullable()
                ->after('last_detected_change_at')
                ->comment('最近一次探测到的主题总页数（用于分段补齐与>1000页跳过判定）');

            $table->unsignedInteger('crawl_backfill_next_page_number')
                ->nullable()
                ->after('crawl_page_total_last_seen')
                ->comment('分段补齐的下次起始页码（1-based；NULL表示已补齐到当前已知总页数）');

            $table->boolean('is_skipped_by_page_total_limit')
                ->default(false)
                ->after('crawl_backfill_next_page_number')
                ->comment('是否因主题总页数超过阈值（>1000页）而跳过详情抓取（不抓posts）');

            $table->dateTime('skipped_by_page_total_limit_at')
                ->nullable()
                ->after('is_skipped_by_page_total_limit')
                ->comment('首次触发“>1000页跳过”的时间（用于审计排查）');
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table): void {
            $table->dropColumn([
                'crawl_page_total_last_seen',
                'crawl_backfill_next_page_number',
                'is_skipped_by_page_total_limit',
                'skipped_by_page_total_limit_at',
            ]);
        });
    }
};
