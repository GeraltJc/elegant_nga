<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE threads COMMENT = 'NGA 主题（列表页实体 + 增量判定 + 抓取游标 + 截断信息）'");
        DB::statement("ALTER TABLE threads MODIFY COLUMN is_truncated_by_page_limit TINYINT(1) NOT NULL DEFAULT 0 COMMENT '当前是否仍未抓全（受单次页上限影响）'");
        DB::statement("ALTER TABLE threads MODIFY COLUMN truncated_at_page_number TINYINT UNSIGNED NULL COMMENT '本次抓取到的最后页码（绝对页码，用于排查/提示）'");
    }

    public function down(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE threads COMMENT = 'NGA threads: list entity and crawl cursor'");
        DB::statement("ALTER TABLE threads MODIFY COLUMN is_truncated_by_page_limit TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''");
        DB::statement("ALTER TABLE threads MODIFY COLUMN truncated_at_page_number TINYINT UNSIGNED NULL COMMENT ''");
    }
};
