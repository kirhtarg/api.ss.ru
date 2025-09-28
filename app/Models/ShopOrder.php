<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'user_id',
        'status_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'items',
        'subtotal',
        'discount_amount',
        'total_amount',
        'total_quantity',
        'payment_method',
        'shipping_method',
        'shipping_address',
        'notes',
        'ip_address',
        'user_agent',
        'metadata'
    ];

    protected $casts = [
        'items' => 'array',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'total_quantity' => 'integer',
        'metadata' => 'array'
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}