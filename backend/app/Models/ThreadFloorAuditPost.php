<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 缺楼层审计楼层明细模型，用于记录单楼层修补状态与结果。
 */
class ThreadFloorAuditPost extends Model
{
    use HasFactory;

    /**
     * 允许批量赋值，便于写入审计明细。
     *
     * @var array<int, string>
     */
    protected $guarded = [];

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
     * 关联所属审计主题明细。
     *
     * @return BelongsTo
     */
    public function auditThread(): BelongsTo
    {
        return $this->belongsTo(ThreadFloorAuditThread::class, 'audit_thread_id');
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
