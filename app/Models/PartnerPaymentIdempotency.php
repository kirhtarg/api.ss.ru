<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerPaymentIdempotency extends Model
{
    protected $fillable = [
        'partner_id', 'partner_order_id', 'idempotency_key', 'request_hash',
        'status', 'payment_transaction_id', 'result',
    ];

    protected $casts = ['result' => 'array'];
}
