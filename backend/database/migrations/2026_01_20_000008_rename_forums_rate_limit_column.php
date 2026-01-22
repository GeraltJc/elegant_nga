<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (!Schema::hasTable('forums')) {
            return;
        }

        if (
            Schema::hasColumn('forums', 'request_rate_limit_rps')
            && !Schema::hasColumn('forums', 'request_rate_limit_per_sec')
        ) {
            DB::statement(
                "ALTER TABLE forums CHANGE request_rate_limit_rps request_rate_limit_per_sec ".
                "DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT '抓取限速（每秒请求数上限）'"
            );
        }
    }

    public function down(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (!Schema::hasTable('forums')) {
            return;
        }

        if (
            Schema::hasColumn('forums', 'request_rate_limit_per_sec')
            && !Schema::hasColumn('forums', 'request_rate_limit_rps')
        ) {
            DB::statement(
                "ALTER TABLE forums CHANGE request_rate_limit_per_sec request_rate_limit_rps ".
                "TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '抓取限速（每秒请求数上限）'"
            );
        }
    }
};
