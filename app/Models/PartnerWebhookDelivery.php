<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerWebhookDelivery extends Model
{
    protected $fillable = ['public_id', 'partner_id', 'partner_order_id', 'event', 'payload', 'status', 'attempts', 'response_status', 'response_body', 'last_error', 'next_attempt_at', 'delivered_at'];

    protected $casts = ['payload' => 'array', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function partnerOrder()
    {
        return $this->belongsTo(PartnerOrder::class);
    }
}
