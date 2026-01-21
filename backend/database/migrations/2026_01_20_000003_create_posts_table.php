<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thread_id')->constrained('threads')->cascadeOnDelete();
            $table->unsignedBigInteger('source_post_id');
            $table->unsignedInteger('floor_number');
            $table->string('author_name', 80);
            $table->unsignedBigInteger('author_source_user_id')->nullable();
            $table->dateTime('post_created_at');
            $table->mediumText('content_html');
            $table->char('content_fingerprint_sha256', 64);
            $table->boolean('is_deleted_by_source')->default(false);
            $table->boolean('is_folded_by_source')->default(false);
            $table->dateTime('content_last_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'source_post_id']);
            $table->unique(['thread_id', 'floor_number']);
            $table->comment('NGA posts: current version content');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
