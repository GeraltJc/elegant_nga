<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_runs', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('forum_id')->constrained('forums')->cascadeOnDelete()->comment('所属版块（forums.id）');
            $table->dateTime('run_started_at')->comment('本次运行开始时间');
            $table->dateTime('run_finished_at')->nullable()->comment('本次运行结束时间');
            $table->string('run_trigger_text', 30)->comment('触发来源（schedule_12h/manual）');
            $table->date('date_window_start')->nullable()->comment('自然日窗口起（上海时区）');
            $table->date('date_window_end')->nullable()->comment('自然日窗口止（上海时区）');
            $table->unsignedInteger('thread_scanned_count')->default(0)->comment('扫描主题数（窗口过滤后）');
            $table->unsignedInteger('thread_change_detected_count')->default(0)->comment('last_reply_at 变化判定数');
            $table->unsignedInteger('thread_updated_count')->default(0)->comment('成功完成更新的主题数');
            $table->unsignedInteger('http_request_count')->default(0)->comment('HTTP 请求次数（含重试）');
            $table->dateTime('created_at')->nullable()->comment('创建时间');
            $table->dateTime('updated_at')->nullable()->comment('更新时间');

            $table->index(['forum_id', 'run_started_at']);
            $table->comment('抓取运行记录（每次 12 小时任务/手动任务的汇总审计）');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_runs');
    }
};
