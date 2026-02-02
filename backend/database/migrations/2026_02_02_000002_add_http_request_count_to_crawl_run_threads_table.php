<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_run_threads', function (Blueprint $table): void {
            $table->unsignedInteger('http_request_count')
                ->default(0)
                ->after('updated_post_count')
                ->comment('该主题处理期间的 HTTP 请求次数（含重试）');

            $table->index(['crawl_run_id', 'http_request_count']);
        });
    }

    public function down(): void
    {
        Schema::table('crawl_run_threads', function (Blueprint $table): void {
            $table->dropIndex(['crawl_run_id', 'http_request_count']);
            $table->dropColumn('http_request_count');
        });
    }
};

