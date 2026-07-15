<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopOzonProductBinding extends Model
{
    protected $fillable = ['account_id', 'good_id', 'variation_id', 'offer_id', 'product_id', 'sku', 'status', 'payload_hash', 'errors', 'last_synced_at'];
    protected $casts = ['errors' => 'array', 'last_synced_at' => 'datetime'];
}
