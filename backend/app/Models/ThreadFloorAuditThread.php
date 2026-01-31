<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 缺楼层审计明细模型，用于记录单主题的缺口与修补结果。
 */
class ThreadFloorAuditThread extends Model
{
    use HasFactory;

    /**
     * 允许批量赋值，便于记录审计明细。
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * JSON 与时间字段转为对应类型，便于业务消费。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'repair_attempted_at' => 'datetime',
        'repair_finished_at' => 'datetime',
    ];

    /**
     * 关联所属审计运行。
     *
     * @return BelongsTo
     */
    public function auditRun(): BelongsTo
    {
        return $this->belongsTo(ThreadFloorAuditRun::class, 'audit_run_id');
    }

    /**
     * 关联所属主题。
     *
     * @return BelongsTo
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
