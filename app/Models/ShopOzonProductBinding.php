<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopOzonProductBinding extends Model
{
    protected $fillable = ['account_id', 'good_id', 'variation_id', 'is_variation', 'offer_id', 'product_id', 'sku', 'status', 'payload_hash', 'synced_attributes', 'synced_variation_attributes', 'errors', 'remote_payload', 'last_synced_at', 'remote_updated_at'];
    protected $casts = ['is_variation' => 'boolean', 'synced_attributes' => 'array', 'synced_variation_attributes' => 'array', 'errors' => 'array', 'remote_payload' => 'array', 'last_synced_at' => 'datetime', 'remote_updated_at' => 'datetime'];

    public function good(): BelongsTo { return $this->belongsTo(ShopGood::class, 'good_id'); }
    public function variation(): BelongsTo { return $this->belongsTo(ShopGoodVariation::class, 'variation_id'); }
}
