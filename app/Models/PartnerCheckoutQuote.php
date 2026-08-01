<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerCheckoutQuote extends Model
{
    protected $fillable = [
        'public_id', 'partner_id', 'request_hash', 'request_payload', 'snapshot',
        'expires_at', 'consumed_at', 'consumed_by_partner_order_id',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'snapshot' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
