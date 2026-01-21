<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('forum_id')->constrained('forums')->cascadeOnDelete();
            $table->unsignedBigInteger('source_thread_id');
            $table->string('title', 200);
            $table->string('title_prefix_text', 40)->nullable();
            $table->string('author_name', 80);
            $table->unsignedBigInteger('author_source_user_id')->nullable();
            $table->dateTime('thread_created_at');
            $table->dateTime('last_reply_at')->nullable();
            $table->unsignedInteger('reply_count_display')->default(0);
            $table->unsignedInteger('view_count_display')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_digest')->default(false);
            $table->unsignedSmallInteger('first_seen_on_list_page_number')->nullable();
            $table->unsignedSmallInteger('last_seen_on_list_page_number')->nullable();
            $table->boolean('is_truncated_by_page_limit')->default(false);
            $table->unsignedTinyInteger('truncated_at_page_number')->nullable();
            $table->dateTime('last_crawled_at')->nullable();
            $table->dateTime('last_detected_change_at')->nullable();
            $table->unsignedInteger('crawl_cursor_max_floor_number')->nullable();
            $table->unsignedBigInteger('crawl_cursor_max_source_post_id')->nullable();
            $table->dateTime('title_last_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['forum_id', 'source_thread_id']);
            $table->index(['forum_id', 'thread_created_at']);
            $table->index(['forum_id', 'last_reply_at']);
            $table->index(['forum_id', 'title']);
            $table->comment('NGA threads: list entity and crawl cursor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};
