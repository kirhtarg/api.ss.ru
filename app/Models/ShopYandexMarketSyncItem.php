<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopYandexMarketSyncItem extends Model
{
    protected $fillable = [
        'run_id', 'good_id', 'variation_id', 'offer_id', 'status',
        'request_payload', 'response_payload', 'errors',
    ];

    protected $casts = ['request_payload' => 'array', 'response_payload' => 'array', 'errors' => 'array'];

    public function run(): BelongsTo { return $this->belongsTo(ShopYandexMarketSyncRun::class, 'run_id'); }
}
