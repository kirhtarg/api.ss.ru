<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopYandexMarketSyncRun extends Model
{
    protected $fillable = [
        'account_id', 'user_id', 'type', 'status', 'good_ids', 'total', 'processed', 'succeeded', 'failed',
        'requests', 'errors', 'meta', 'error_message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'meta' => 'array',
        'good_ids' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ShopYandexMarketSyncItem::class, 'run_id');
    }
}
