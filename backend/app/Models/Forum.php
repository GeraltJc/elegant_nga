<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 论坛版块模型，负责保存抓取配置与版块基础信息。
 */
class Forum extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'crawl_page_limit' => 'integer',
        'request_rate_limit_per_sec' => 'float',
    ];

    /**
     * 关联该版块下的主题列表（threads）。
     *
     * @return HasMany
     */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    /**
     * 关联该版块的抓取运行记录（crawl_runs）。
     *
     * @return HasMany
     */
    public function crawlRuns(): HasMany
    {
        return $this->hasMany(CrawlRun::class);
    }
}
