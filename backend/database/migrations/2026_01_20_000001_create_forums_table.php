<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forums', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('source_forum_id')->unique();
            $table->string('forum_name', 100)->nullable();
            $table->string('list_url', 255);
            $table->unsignedTinyInteger('crawl_page_limit')->default(5);
            $table->decimal('request_rate_limit_per_sec', 5, 2)->default(1.00);
            $table->timestamps();
            $table->comment('NGA forum config and crawl strategy');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forums');
    }
};
