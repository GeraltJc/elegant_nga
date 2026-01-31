<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 楼层修补尝试模型，用于记录单楼层修补次数与最近尝试时间。
 */
class ThreadFloorRepairAttempt extends Model
{
    use HasFactory;

    /**
     * 允许批量赋值，便于写入尝试次数。
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * 时间字段转为 Carbon 实例，便于审计。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_attempted_at' => 'datetime',
    ];

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
