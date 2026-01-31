<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建缺楼层审计与修补记录表。
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('thread_floor_audit_runs', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('run_started_at')->comment('审计开始时间');
            $table->dateTime('run_finished_at')->nullable()->comment('审计结束时间');
            $table->string('run_trigger_text', 30)->comment('触发来源（manual/cron等）');
            $table->boolean('repair_enabled')->default(false)->comment('是否包含修补步骤');
            $table->unsignedInteger('total_thread_count')->default(0)->comment('扫描主题数');
            $table->unsignedInteger('missing_thread_count')->default(0)->comment('缺楼层主题数');
            $table->unsignedInteger('repaired_thread_count')->default(0)->comment('修补成功主题数');
            $table->unsignedInteger('partial_thread_count')->default(0)->comment('修补未完全主题数');
            $table->unsignedInteger('failed_thread_count')->default(0)->comment('修补失败主题数');
            $table->unsignedInteger('failed_http_count')->default(0)->comment('修补失败 - HTTP 类统计');
            $table->unsignedInteger('failed_parse_count')->default(0)->comment('修补失败 - 解析类统计');
            $table->unsignedInteger('failed_db_count')->default(0)->comment('修补失败 - 数据库类统计');
            $table->unsignedInteger('failed_unknown_count')->default(0)->comment('修补失败 - 未知类统计');
            $table->timestamps();
        });

        Schema::create('thread_floor_audit_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audit_run_id')
                ->constrained('thread_floor_audit_runs')
                ->cascadeOnDelete()
                ->comment('所属审计运行（thread_floor_audit_runs.id）');
            $table->foreignId('thread_id')
                ->constrained('threads')
                ->cascadeOnDelete()
                ->comment('所属主题（threads.id）');
            $table->unsignedBigInteger('source_thread_id')->comment('NGA tid');
            $table->unsignedInteger('max_floor_number')->comment('修补前最大楼层号（0基）');
            $table->unsignedInteger('post_count')->comment('修补前楼层条数');
            $table->unsignedInteger('missing_floor_count')->comment('缺失楼层数量');
            $table->json('missing_floors_json')->comment('缺失楼层号列表（0基）');
            $table->unsignedInteger('ignored_floor_count')->default(0)->comment('因超次数跳过的楼层数量');
            $table->json('ignored_floors_json')->comment('因超次数跳过的楼层号列表（0基）');
            $table->string('repair_status', 20)->default('missing')->comment('修补状态：missing/repaired/partial/failed/skipped');
            $table->unsignedBigInteger('repair_crawl_run_id')->nullable()->comment('修补触发的 crawl_runs.id');
            $table->dateTime('repair_attempted_at')->nullable()->comment('修补开始时间');
            $table->dateTime('repair_finished_at')->nullable()->comment('修补结束时间');
            $table->unsignedInteger('repair_after_max_floor_number')->nullable()->comment('修补后最大楼层号（0基）');
            $table->unsignedInteger('repair_after_post_count')->nullable()->comment('修补后楼层条数');
            $table->unsignedInteger('repair_remaining_floor_count')->nullable()->comment('修补后仍缺楼层数量');
            $table->json('repair_remaining_floors_json')->nullable()->comment('修补后仍缺楼层号列表（0基）');
            $table->string('repair_error_summary', 200)->nullable()->comment('修补失败摘要');
            $table->string('repair_error_category', 20)->nullable()->comment('修补失败归因：http/parse/db/unknown');
            $table->unsignedSmallInteger('repair_http_error_code')->nullable()->comment('修补失败的 HTTP 状态码');
            $table->timestamps();
        });

        // 说明：SQLite 不支持表注释语法，仅在 MySQL/MariaDB 下执行。
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE thread_floor_audit_runs COMMENT = '主题缺楼层审计运行记录'");
            DB::statement("ALTER TABLE thread_floor_audit_threads COMMENT = '主题缺楼层审计明细与修补结果'");
        }
    }

    /**
     * 回滚缺楼层审计与修补记录表。
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('thread_floor_audit_threads');
        Schema::dropIfExists('thread_floor_audit_runs');
    }
};
