<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 补充审计主题明细表的跳过计数字段。
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasColumn('thread_floor_audit_threads', 'ignored_floor_count')) {
            Schema::table('thread_floor_audit_threads', function (Blueprint $table): void {
                $table->unsignedInteger('ignored_floor_count')
                    ->default(0)
                    ->comment('因超次数跳过的楼层数量')
                    ->after('missing_floor_count');
            });
        }
    }

    /**
     * 回滚审计主题明细表的跳过计数字段。
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasColumn('thread_floor_audit_threads', 'ignored_floor_count')) {
            Schema::table('thread_floor_audit_threads', function (Blueprint $table): void {
                $table->dropColumn('ignored_floor_count');
            });
        }
    }
};
