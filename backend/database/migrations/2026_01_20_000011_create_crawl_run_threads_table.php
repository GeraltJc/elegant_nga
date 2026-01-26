<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_run_threads', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('crawl_run_id')->constrained('crawl_runs')->cascadeOnDelete()->comment('所属运行（crawl_runs.id）');
            $table->foreignId('thread_id')->constrained('threads')->cascadeOnDelete()->comment('所属主题（threads.id）');
            $table->boolean('change_detected_by_last_reply_at')->default(false)->comment('是否因 last_reply_at 变化入队');
            $table->dateTime('detected_last_reply_at')->nullable()->comment('本次检测到的 last_reply_at');
            $table->unsignedTinyInteger('fetched_page_count')->default(0)->comment('本次实际抓取页数');
            $table->boolean('page_limit_applied')->default(false)->comment('是否触发单次页上限');
            $table->unsignedInteger('new_post_count')->default(0)->comment('新增楼层数');
            $table->unsignedInteger('updated_post_count')->default(0)->comment('更新楼层数（编辑/删/折叠）');
            $table->unsignedInteger('http_error_code')->nullable()->comment('失败时 HTTP 状态码');
            $table->string('error_summary', 200)->nullable()->comment('错误摘要（固定枚举，可带 msg）');
            $table->dateTime('started_at')->comment('开始处理该主题的时间');
            $table->dateTime('finished_at')->nullable()->comment('完成处理该主题的时间');

            $table->unique(['crawl_run_id', 'thread_id']);
            $table->index(['crawl_run_id', 'error_summary']);
            $table->comment('抓取运行-主题明细（每次 run 对每个主题的处理结果与错误信息）');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_run_threads');
    }
};
