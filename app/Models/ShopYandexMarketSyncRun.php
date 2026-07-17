<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopYandexMarketSyncRun extends Model
{
    protected $fillable = [
        'user_id', 'type', 'status', 'total', 'processed', 'succeeded', 'failed',
        'requests', 'errors', 'meta', 'error_message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
