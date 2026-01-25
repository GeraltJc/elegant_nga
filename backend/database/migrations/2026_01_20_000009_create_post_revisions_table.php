<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 创建楼层历史版本表，用于记录内容/删除/折叠的快照审计。
 */
return new class extends Migration
{
    /**
     * 执行迁移：创建 post_revisions 表及中文注释。
     */
    public function up(): void
    {
        Schema::create('post_revisions', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete()->comment('所属楼层（posts.id）');
            $table->dateTime('revision_created_at')->comment('我方发现变更时间（抓取时刻）');
            $table->dateTime('source_edited_at')->nullable()->comment('来源编辑时间（若可得）');
            $table->mediumText('content_html')->comment('该版本内容快照');
            $table->char('content_fingerprint_sha256', 64)->comment('该版本指纹（SHA256）');
            $table->string('change_detected_reason', 120)->comment(
                '变更原因（token 列表，以 ; 分隔：content_fingerprint_changed / marked_deleted_by_source / marked_folded_by_source）'
            );
            $table->unsignedBigInteger('crawl_run_thread_id')->nullable()->comment('抓取明细 ID（crawl_run_threads.id，可空）');
            $table->comment('NGA 楼层历史版本（内容变更/删除/折叠的快照审计）');
        });
    }

    /**
     * 回滚迁移：删除 post_revisions 表。
     */
    public function down(): void
    {
        Schema::dropIfExists('post_revisions');
    }
};
