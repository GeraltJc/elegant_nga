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

        DB::statement(<<<'SQL'
ALTER TABLE forums
    MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    MODIFY COLUMN source_forum_id INT UNSIGNED NOT NULL COMMENT 'NGA fid',
    MODIFY COLUMN forum_name VARCHAR(100) NULL COMMENT '版块名称（可选）',
    MODIFY COLUMN list_url VARCHAR(255) NOT NULL COMMENT '版块列表 URL（排查用）',
    MODIFY COLUMN crawl_page_limit TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '单次抓取页上限',
    MODIFY COLUMN request_rate_limit_per_sec DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT '抓取限速（每秒请求数上限）',
    MODIFY COLUMN created_at TIMESTAMP NULL COMMENT '创建时间',
    MODIFY COLUMN updated_at TIMESTAMP NULL COMMENT '更新时间',
    COMMENT='NGA 版块配置与抓取策略（支持多 fid 扩展）'
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE threads
    MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    MODIFY COLUMN forum_id BIGINT UNSIGNED NOT NULL COMMENT '所属版块（forums.id）',
    MODIFY COLUMN source_thread_id BIGINT UNSIGNED NOT NULL COMMENT 'NGA tid',
    MODIFY COLUMN title VARCHAR(200) NOT NULL COMMENT '标题原文',
    MODIFY COLUMN title_prefix_text VARCHAR(40) NULL COMMENT '标题前缀文本（如 水）',
    MODIFY COLUMN author_name VARCHAR(80) NOT NULL COMMENT '楼主显示名',
    MODIFY COLUMN author_source_user_id BIGINT UNSIGNED NULL COMMENT '楼主 uid（可选）',
    MODIFY COLUMN thread_created_at DATETIME NOT NULL COMMENT '发帖时间（列表口径）',
    MODIFY COLUMN last_reply_at DATETIME NULL COMMENT '最后回复时间（变化判定）',
    MODIFY COLUMN reply_count_display INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '列表页显示回复数',
    MODIFY COLUMN view_count_display INT UNSIGNED NULL COMMENT '列表页显示浏览数',
    MODIFY COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否置顶',
    MODIFY COLUMN is_digest TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否精华',
    MODIFY COLUMN first_seen_on_list_page_number SMALLINT UNSIGNED NULL COMMENT '首次发现所在列表页码',
    MODIFY COLUMN last_seen_on_list_page_number SMALLINT UNSIGNED NULL COMMENT '最近扫描到所在列表页码',
    MODIFY COLUMN is_truncated_by_page_limit TINYINT(1) NOT NULL DEFAULT 0 COMMENT '当前是否仍未抓全（受单次页上限影响）',
    MODIFY COLUMN truncated_at_page_number TINYINT UNSIGNED NULL COMMENT '本次抓取到的最后页码（绝对页码，用于排查/提示）',
    MODIFY COLUMN last_crawled_at DATETIME NULL COMMENT '最近成功抓取时间',
    MODIFY COLUMN last_detected_change_at DATETIME NULL COMMENT '最近一次检测到变化时间',
    MODIFY COLUMN crawl_page_total_last_seen INT UNSIGNED NULL COMMENT '最近一次探测到的主题总页数（分段补齐/跳过判定）',
    MODIFY COLUMN crawl_backfill_next_page_number INT UNSIGNED NULL COMMENT '分段补齐下次起始页码（NULL 表示已补齐）',
    MODIFY COLUMN is_skipped_by_page_total_limit TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否因主题总页数>1000页跳过详情',
    MODIFY COLUMN skipped_by_page_total_limit_at DATETIME NULL COMMENT '首次触发>1000页跳过时间',
    MODIFY COLUMN crawl_cursor_max_floor_number INT UNSIGNED NULL COMMENT '已抓到最大楼层号',
    MODIFY COLUMN crawl_cursor_max_source_post_id BIGINT UNSIGNED NULL COMMENT '已抓到最大 pid',
    MODIFY COLUMN title_last_changed_at DATETIME NULL COMMENT '标题最后变化时间',
    MODIFY COLUMN created_at TIMESTAMP NULL COMMENT '创建时间',
    MODIFY COLUMN updated_at TIMESTAMP NULL COMMENT '更新时间',
    COMMENT='NGA 主题（列表页实体 + 增量判定 + 抓取游标 + 截断信息）'
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE posts
    MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    MODIFY COLUMN thread_id BIGINT UNSIGNED NOT NULL COMMENT '所属主题（threads.id）',
    MODIFY COLUMN source_post_id BIGINT UNSIGNED NOT NULL COMMENT 'NGA pid',
    MODIFY COLUMN floor_number INT UNSIGNED NOT NULL COMMENT '楼层号（1 为首帖）',
    MODIFY COLUMN author_name VARCHAR(80) NOT NULL COMMENT '楼层作者显示名',
    MODIFY COLUMN author_source_user_id BIGINT UNSIGNED NULL COMMENT '楼层作者 uid（可选）',
    MODIFY COLUMN post_created_at DATETIME NOT NULL COMMENT '发帖时间',
    MODIFY COLUMN content_html MEDIUMTEXT NOT NULL COMMENT '楼层内容（当前版本 HTML）',
    MODIFY COLUMN content_fingerprint_sha256 CHAR(64) NOT NULL COMMENT '内容指纹（SHA256）',
    MODIFY COLUMN is_deleted_by_source TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否被源站删除',
    MODIFY COLUMN is_folded_by_source TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否被源站折叠',
    MODIFY COLUMN content_last_changed_at DATETIME NULL COMMENT '内容最后变化时间',
    MODIFY COLUMN created_at TIMESTAMP NULL COMMENT '创建时间',
    MODIFY COLUMN updated_at TIMESTAMP NULL COMMENT '更新时间',
    COMMENT='NGA 楼层（当前版本内容，支持增量追加与删除/折叠标记）'
SQL);
    }

    public function down(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(<<<'SQL'
ALTER TABLE forums
    MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '',
    MODIFY COLUMN source_forum_id INT UNSIGNED NOT NULL COMMENT '',
    MODIFY COLUMN forum_name VARCHAR(100) NULL COMMENT '',
    MODIFY COLUMN list_url VARCHAR(255) NOT NULL COMMENT '',
    MODIFY COLUMN crawl_page_limit TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '',
    MODIFY COLUMN request_rate_limit_per_sec DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT '',
    MODIFY COLUMN created_at TIMESTAMP NULL COMMENT '',
    MODIFY COLUMN updated_at TIMESTAMP NULL COMMENT '',
    COMMENT='NGA forum config and crawl strategy'
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE threads
    MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '',
    MODIFY COLUMN forum_id BIGINT UNSIGNED NOT NULL COMMENT '',
    MODIFY COLUMN source_thread_id BIGINT UNSIGNED NOT NULL COMMENT '',
    MODIFY COLUMN title VARCHAR(200) NOT NULL COMMENT '',
    MODIFY COLUMN title_prefix_text VARCHAR(40) NULL COMMENT '',
    MODIFY COLUMN author_name VARCHAR(80) NOT NULL COMMENT '',
    MODIFY COLUMN author_source_user_id BIGINT UNSIGNED NULL COMMENT '',
    MODIFY COLUMN thread_created_at DATETIME NOT NULL COMMENT '',
    MODIFY COLUMN last_reply_at DATETIME NULL COMMENT '',
    MODIFY COLUMN reply_count_display INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '',
    MODIFY COLUMN view_count_display INT UNSIGNED NULL COMMENT '',
    MODIFY COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 COMMENT '',
    MODIFY COLUMN is_digest TINYINT(1) NOT NULL DEFAULT 0 COMMENT '',
    MODIFY COLUMN first_seen_on_list_page_number SMALLINT UNSIGNED NULL COMMENT '',
    MODIFY COLUMN last_seen_on_list_page_number SMALLINT UNSIGNED NULL COMMENT '',
    MODIFY COLUMN is_truncated_by_page_limit TINYINT(1) NOT NULL DEFAULT 0 COMMENT '',
    MODIFY COLUMN truncated_at_page_number TINYINT UNSIGNED NULL COMMENT '',
    MODIFY COLUMN last_crawled_at DATETIME NULL COMMENT '',
    MODIFY COLUMN last_detected_change_at DATETIME NULL COMMENT '',
    MODIFY COLUMN crawl_page_total_last_seen INT UNSIGNED NULL COMMENT '',
    MODIFY COLUMN crawl_backfill_next_page_number INT UNSIGNED NULL COMMENT '',
    MODIFY COLUMN is_skipped_by_page_total_limit TINYINT(1) NOT NULL DEFAULT 0 COMMENT '',
    MODIFY COLUMN skipped_by_page_total_limit_at DATETIME NULL COMMENT '',
    MODIFY COLUMN crawl_cursor_max_floor_number INT UNSIGNED NULL COMMENT '',
    MODIFY COLUMN crawl_cursor_max_source_post_id BIGINT UNSIGNED NULL COMMENT '',
    MODIFY COLUMN title_last_changed_at DATETIME NULL COMMENT '',
    MODIFY COLUMN created_at TIMESTAMP NULL COMMENT '',
    MODIFY COLUMN updated_at TIMESTAMP NULL COMMENT '',
    COMMENT='NGA threads: list entity and crawl cursor'
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE posts
    MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '',
    MODIFY COLUMN thread_id BIGINT UNSIGNED NOT NULL COMMENT '',
    MODIFY COLUMN source_post_id BIGINT UNSIGNED NOT NULL COMMENT '',
    MODIFY COLUMN floor_number INT UNSIGNED NOT NULL COMMENT '',
    MODIFY COLUMN author_name VARCHAR(80) NOT NULL COMMENT '',
    MODIFY COLUMN author_source_user_id BIGINT UNSIGNED NULL COMMENT '',
    MODIFY COLUMN post_created_at DATETIME NOT NULL COMMENT '',
    MODIFY COLUMN content_html MEDIUMTEXT NOT NULL COMMENT '',
    MODIFY COLUMN content_fingerprint_sha256 CHAR(64) NOT NULL COMMENT '',
    MODIFY COLUMN is_deleted_by_source TINYINT(1) NOT NULL DEFAULT 0 COMMENT '',
    MODIFY COLUMN is_folded_by_source TINYINT(1) NOT NULL DEFAULT 0 COMMENT '',
    MODIFY COLUMN content_last_changed_at DATETIME NULL COMMENT '',
    MODIFY COLUMN created_at TIMESTAMP NULL COMMENT '',
    MODIFY COLUMN updated_at TIMESTAMP NULL COMMENT '',
    COMMENT='NGA posts: current version content'
SQL);
    }
};
