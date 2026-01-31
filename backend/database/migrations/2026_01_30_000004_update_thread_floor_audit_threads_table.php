<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 调整审计主题明细表字段，移除楼层列表 JSON 字段。
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasColumn('thread_floor_audit_threads', 'missing_floors_json')) {
            Schema::table('thread_floor_audit_threads', function (Blueprint $table): void {
                $table->dropColumn('missing_floors_json');
            });
        }

        if (Schema::hasColumn('thread_floor_audit_threads', 'ignored_floors_json')) {
            Schema::table('thread_floor_audit_threads', function (Blueprint $table): void {
                $table->dropColumn('ignored_floors_json');
            });
        }

        if (Schema::hasColumn('thread_floor_audit_threads', 'repair_remaining_floors_json')) {
            Schema::table('thread_floor_audit_threads', function (Blueprint $table): void {
                $table->dropColumn('repair_remaining_floors_json');
            });
        }
    }

    /**
     * 回滚审计主题明细表字段。
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('thread_floor_audit_threads', function (Blueprint $table): void {
            $table->json('missing_floors_json')->comment('缺失楼层号列表（0基）');
            $table->json('ignored_floors_json')->comment('因超次数跳过的楼层号列表（0基）');
            $table->json('repair_remaining_floors_json')->nullable()->comment('修补后仍缺楼层号列表（0基）');
        });
    }
};
