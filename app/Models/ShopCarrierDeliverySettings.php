<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopCarrierDeliverySettings extends Model
{
    use HasFactory;

    protected $table = 'shop_carrier_delivery_settings';

    protected $fillable = [
        'carrier',
        'api_url',
        'api_token',
        'client_id',
        'client_secret',
        'warehouse_id',
        'sender_city',
        'sender_street',
        'sender_house',
        'sender_postal_code',
        'default_weight',
        'default_length',
        'default_width',
        'default_height',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'default_weight' => 'decimal:2',
        'default_length' => 'decimal:2',
        'default_width' => 'decimal:2',
        'default_height' => 'decimal:2',
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    public static function getActive(string $carrier): ?self
    {
        if (ShopDeliveryMethod::where('type', $carrier)->where('is_active', false)->exists()) {
            return null;
        }

        return self::where('carrier', $carrier)->where('is_active', true)->first();
    }
}
