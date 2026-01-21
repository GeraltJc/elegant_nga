<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Forum extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'crawl_page_limit' => 'integer',
        'request_rate_limit_per_sec' => 'float',
    ];

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }
}
