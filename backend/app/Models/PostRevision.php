<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 楼层历史版本快照，用于追踪内容/删除/折叠的变更记录。
 */
class PostRevision extends Model
{
    /**
     * 禁用 created_at/updated_at，由业务字段承载时间语义。
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 允许批量赋值，便于写入快照。
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * 日期字段转为 Carbon 实例，方便比较与展示。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'revision_created_at' => 'datetime',
        'source_edited_at' => 'datetime',
    ];

    /**
     * 关联所属楼层（posts）。
     *
     * @return BelongsTo
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * 关联产生该版本的抓取明细（crawl_run_threads）。
     *
     * @return BelongsTo
     */
    public function crawlRunThread(): BelongsTo
    {
        return $this->belongsTo(CrawlRunThread::class);
    }
}
