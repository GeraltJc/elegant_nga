<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('audit_run_id')
                ->nullable()
                ->after('run_trigger_text')
                ->comment('关联缺楼层审计运行（thread_floor_audit_runs.id），用于把审计触发的抓取归并到一次审计');

            $table->index('audit_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('crawl_runs', function (Blueprint $table): void {
            $table->dropIndex(['audit_run_id']);
            $table->dropColumn('audit_run_id');
        });
    }
};

