<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopOrderPackage extends Model
{
    protected $fillable = [
        'order_id', 'number', 'weight', 'length', 'width', 'height',
        'source', 'confirmed_at', 'items',
    ];

    protected $casts = [
        'weight' => 'decimal:3',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'items' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(ShopOrder::class, 'order_id');
    }
}
