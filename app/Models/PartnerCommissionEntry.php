<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerCommissionEntry extends Model
{
    protected $fillable = ['partner_id', 'partner_order_id', 'partner_payout_id', 'type', 'status', 'amount', 'currency', 'reason', 'metadata', 'recognized_at', 'paid_at'];

    protected $casts = ['amount' => 'decimal:2', 'metadata' => 'array', 'recognized_at' => 'datetime', 'paid_at' => 'datetime'];

    public function partnerOrder()
    {
        return $this->belongsTo(PartnerOrder::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function payout()
    {
        return $this->belongsTo(PartnerPayout::class, 'partner_payout_id');
    }
}
