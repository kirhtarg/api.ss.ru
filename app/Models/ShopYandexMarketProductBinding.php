<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopYandexMarketProductBinding extends Model
{
    protected $fillable = [
        'account_id', 'good_id', 'variation_id', 'offer_id', 'market_sku', 'status',
        'content_rating', 'payload_hash', 'errors', 'warnings', 'remote_payload',
        'last_synced_at', 'remote_updated_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'warnings' => 'array',
        'remote_payload' => 'array',
        'last_synced_at' => 'datetime',
        'remote_updated_at' => 'datetime',
    ];

    public function good(): BelongsTo { return $this->belongsTo(ShopGood::class, 'good_id'); }
    public function variation(): BelongsTo { return $this->belongsTo(ShopGoodVariation::class, 'variation_id'); }
}
