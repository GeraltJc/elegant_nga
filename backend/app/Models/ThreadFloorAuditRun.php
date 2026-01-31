<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 缺楼层审计运行模型，用于记录一次缺口扫描与修补的汇总数据。
 */
class ThreadFloorAuditRun extends Model
{
    use HasFactory;

    /**
     * 允许批量赋值，便于记录运行汇总。
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * 时间与布尔字段转为对应类型，便于统计与格式化。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'run_started_at' => 'datetime',
        'run_finished_at' => 'datetime',
        'repair_enabled' => 'boolean',
    ];

    /**
     * 关联该次审计的主题明细。
     *
     * @return HasMany
     */
    public function auditThreads(): HasMany
    {
        return $this->hasMany(ThreadFloorAuditThread::class, 'audit_run_id');
    }
}
