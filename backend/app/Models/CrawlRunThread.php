<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 抓取运行主题明细模型，用于记录单个主题在某次运行中的处理结果。
 */
class CrawlRunThread extends Model
{
    use HasFactory;

    /**
     * 关闭默认时间戳，避免与 started_at/finished_at 语义冲突。
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 允许批量赋值，便于写入审计明细。
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * 将时间与布尔字段转为更易用的类型。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'change_detected_by_last_reply_at' => 'boolean',
        'page_limit_applied' => 'boolean',
        'detected_last_reply_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * 关联所属运行（crawl_runs）。
     *
     * @return BelongsTo
     */
    public function crawlRun(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class);
    }

    /**
     * 关联所属主题（threads）。
     *
     * @return BelongsTo
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * 关联该次运行产生的楼层历史版本快照。
     *
     * @return HasMany
     */
    public function postRevisions(): HasMany
    {
        return $this->hasMany(PostRevision::class, 'crawl_run_thread_id');
    }
}
