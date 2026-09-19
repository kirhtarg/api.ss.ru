<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YcpCheckoutSession extends Model
{
    protected $fillable = ['session_id', 'shop_order_id', 'ycp_order_id', 'status', 'request_hash', 'snapshot'];

    protected $casts = ['snapshot' => 'array'];

    public function shopOrder()
    {
        return $this->belongsTo(ShopOrder::class);
    }
}
