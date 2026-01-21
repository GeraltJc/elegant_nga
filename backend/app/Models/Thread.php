<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    public function forum(): BelongsTo
    {
        return $this->belongsTo(Forum::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
