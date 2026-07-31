<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerPayout extends Model
{
    protected $fillable = ['public_id', 'number', 'partner_id', 'status', 'amount', 'currency', 'entries_count', 'period_from', 'period_to', 'payment_reference', 'comment', 'metadata', 'created_by', 'paid_by', 'paid_at', 'cancelled_at'];

    protected $casts = ['amount' => 'decimal:2', 'entries_count' => 'integer', 'period_from' => 'date', 'period_to' => 'date', 'metadata' => 'array', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function commissions()
    {
        return $this->hasMany(PartnerCommissionEntry::class);
    }
}
