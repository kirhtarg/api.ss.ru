<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopYandexMarketAccount extends Model
{
    protected $fillable = [
        'name', 'api_key', 'api_url', 'business_id', 'campaign_id', 'selection_tag_id',
        'image_base_url', 'is_active', 'last_connection_at', 'last_error', 'capabilities', 'campaigns',
    ];

    protected $hidden = ['api_key'];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_active' => 'boolean',
        'last_connection_at' => 'datetime',
        'capabilities' => 'array',
        'campaigns' => 'array',
    ];

    public function categoryMappings(): HasMany
    {
        return $this->hasMany(ShopYandexMarketCategoryMapping::class, 'account_id');
    }
}
