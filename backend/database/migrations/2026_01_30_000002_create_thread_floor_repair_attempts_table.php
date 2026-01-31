<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建缺楼层修补尝试次数表。
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('thread_floor_repair_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thread_id')
                ->constrained('threads')
                ->cascadeOnDelete()
                ->comment('所属主题（threads.id）');
            $table->unsignedBigInteger('source_thread_id')->comment('NGA tid');
            $table->unsignedInteger('floor_number')->comment('楼层号（0基）');
            $table->unsignedTinyInteger('attempt_count')->default(0)->comment('修补尝试次数');
            $table->dateTime('last_attempted_at')->nullable()->comment('最近一次尝试时间');
            $table->timestamps();
            $table->unique(['thread_id', 'floor_number']);
        });

        // 说明：SQLite 不支持表注释语法，仅在 MySQL/MariaDB 下执行。
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE thread_floor_repair_attempts COMMENT = '楼层修补尝试次数记录'");
        }
    }

    /**
     * 回滚缺楼层修补尝试次数表。
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('thread_floor_repair_attempts');
    }
};
