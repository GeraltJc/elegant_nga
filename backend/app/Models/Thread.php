<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 主题模型，承载列表信息与抓取游标等核心数据。
 */
class Thread extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'thread_created_at' => 'datetime',
        'last_reply_at' => 'datetime',
        'is_pinned' => 'boolean',
        'is_digest' => 'boolean',
        'is_truncated_by_page_limit' => 'boolean',
        'last_crawled_at' => 'datetime',
        'last_detected_change_at' => 'datetime',
        'title_last_changed_at' => 'datetime',
        'is_skipped_by_page_total_limit' => 'boolean',
        'skipped_by_page_total_limit_at' => 'datetime',
    ];

    /**
     * 关联所属版块（forums）。
     *
     * @return BelongsTo
     */
    public function forum(): BelongsTo
    {
        return $this->belongsTo(Forum::class);
    }

    /**
     * 关联该主题下的楼层列表（posts）。
     *
     * @return HasMany
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * 关联该主题的抓取运行明细（crawl_run_threads）。
     *
     * @return HasMany
     */
    public function crawlRunThreads(): HasMany
    {
        return $this->hasMany(CrawlRunThread::class);
    }
}
