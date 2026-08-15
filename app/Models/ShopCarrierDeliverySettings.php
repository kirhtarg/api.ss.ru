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
        'oauth_client_id',
        'oauth_client_secret',
        'oauth_access_token',
        'oauth_expires_at',
        'seller_id',
        'store_domain',
        'logistics_schema',
    ];

    protected $casts = [
        'default_weight' => 'decimal:2',
        'default_length' => 'decimal:2',
        'default_width' => 'decimal:2',
        'default_height' => 'decimal:2',
        'settings' => 'array',
        'is_active' => 'boolean',
        'oauth_client_secret' => 'encrypted',
        'oauth_access_token' => 'encrypted',
        'oauth_expires_at' => 'datetime',
    ];

    protected $hidden = [
        'api_token',
        'client_secret',
        'oauth_client_secret',
        'oauth_access_token',
    ];

    public static function getActive(string $carrier): ?self
    {
        $methodActive = app(\App\Services\ShopDeliveryActivitySyncService::class)->getMethodActive($carrier);
        if ($methodActive === false) {
            return null;
        }

        return self::where('carrier', $carrier)->where('is_active', true)->first();
    }
}
