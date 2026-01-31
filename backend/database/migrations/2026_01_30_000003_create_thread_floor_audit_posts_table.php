<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建缺楼层审计楼层明细表。
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('thread_floor_audit_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audit_run_id')
                ->constrained('thread_floor_audit_runs')
                ->cascadeOnDelete()
                ->comment('所属审计运行（thread_floor_audit_runs.id）');
            $table->foreignId('audit_thread_id')
                ->constrained('thread_floor_audit_threads')
                ->cascadeOnDelete()
                ->comment('所属审计主题明细（thread_floor_audit_threads.id）');
            $table->foreignId('thread_id')
                ->constrained('threads')
                ->cascadeOnDelete()
                ->comment('所属主题（threads.id）');
            $table->unsignedBigInteger('source_thread_id')->comment('NGA tid');
            $table->unsignedInteger('floor_number')->comment('楼层号（0基）');
            $table->string('repair_status', 20)->comment('修补状态：missing/ignored/repaired/still_missing/failed');
            $table->unsignedSmallInteger('attempt_count_before')->default(0)->comment('修补前尝试次数');
            $table->unsignedSmallInteger('attempt_count_after')->nullable()->comment('修补后尝试次数');
            $table->string('repair_error_category', 20)->nullable()->comment('修补失败归因：http/parse/db/unknown');
            $table->unsignedSmallInteger('repair_http_error_code')->nullable()->comment('修补失败的 HTTP 状态码');
            $table->string('repair_error_summary', 200)->nullable()->comment('修补失败摘要');
            $table->timestamps();
            $table->unique(['audit_thread_id', 'floor_number']);
        });

        // 说明：SQLite 不支持表注释语法，仅在 MySQL/MariaDB 下执行。
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE thread_floor_audit_posts COMMENT = '缺楼层审计楼层明细（按楼层记录修补结果）'");
        }
    }

    /**
     * 回滚缺楼层审计楼层明细表。
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('thread_floor_audit_posts');
    }
};
