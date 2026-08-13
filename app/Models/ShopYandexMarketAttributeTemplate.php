<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopYandexMarketAttributeTemplate extends Model
{
    protected $fillable = [
        'account_id',
        'market_parameter_id',
        'market_parameter_name',
        'source_signature',
        'mapping',
    ];

    protected $casts = [
        'mapping' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ShopYandexMarketAccount::class, 'account_id');
    }
}
