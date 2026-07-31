<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerOrder extends Model
{
    protected $fillable = ['public_id', 'partner_id', 'shop_order_id', 'external_order_id', 'idempotency_key', 'request_hash', 'status', 'currency', 'items_amount', 'discount_amount', 'delivery_amount', 'total_amount', 'commission_rate', 'commission_base', 'commission_amount', 'commission_status', 'customer_reference', 'attribution', 'metadata'];

    protected $casts = ['items_amount' => 'decimal:2', 'discount_amount' => 'decimal:2', 'delivery_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'commission_rate' => 'decimal:4', 'commission_base' => 'decimal:2', 'commission_amount' => 'decimal:2', 'customer_reference' => 'array', 'attribution' => 'array', 'metadata' => 'array'];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function shopOrder()
    {
        return $this->belongsTo(ShopOrder::class);
    }

    public function commissions()
    {
        return $this->hasMany(PartnerCommissionEntry::class);
    }
}
