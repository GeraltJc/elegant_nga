<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 楼层主表模型，承载当前版本内容与增量标记。
 */
class Post extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'post_created_at' => 'datetime',
        'is_deleted_by_source' => 'boolean',
        'is_folded_by_source' => 'boolean',
        'content_last_changed_at' => 'datetime',
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

    /**
     * 关联楼层历史版本列表。
     *
     * @return HasMany
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PostRevision::class);
    }
}
