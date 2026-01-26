<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 抓取运行记录模型，用于保存一次抓取任务的汇总审计数据。
 */
class CrawlRun extends Model
{
    use HasFactory;

    /**
     * 允许批量赋值，便于记录运行汇总。
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * 时间与日期字段转为 Carbon 实例，便于统计与格式化。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'run_started_at' => 'datetime',
        'run_finished_at' => 'datetime',
        'date_window_start' => 'date',
        'date_window_end' => 'date',
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
     * 关联该运行的主题处理明细（crawl_run_threads）。
     *
     * @return HasMany
     */
    public function crawlRunThreads(): HasMany
    {
        return $this->hasMany(CrawlRunThread::class);
    }
}
