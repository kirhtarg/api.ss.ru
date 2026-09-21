<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopOzonDeliveryPoint extends Model
{
    protected $table = 'shop_ozon_delivery_points';

    protected $primaryKey = 'delivery_point_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'delivery_point_id', 'name', 'full_address', 'search_text', 'shipment_method_ids',
        'point_data', 'is_active', 'last_sync_run_id',
    ];

    protected $casts = [
        'shipment_method_ids' => 'array',
        'point_data' => 'array',
        'is_active' => 'boolean',
    ];
}
