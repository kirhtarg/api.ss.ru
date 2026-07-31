<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $fillable = ['public_id', 'code', 'name', 'is_active', 'commission_rate', 'commission_status', 'webhook_url', 'webhook_secret', 'allowed_ips', 'metadata'];

    protected $hidden = ['webhook_secret'];

    protected $casts = [
        'is_active' => 'boolean',
        'commission_rate' => 'decimal:4',
        'webhook_secret' => 'encrypted',
        'allowed_ips' => 'array',
        'metadata' => 'array',
    ];

    public function credentials()
    {
        return $this->hasMany(PartnerApiCredential::class);
    }

    public function orders()
    {
        return $this->hasMany(PartnerOrder::class);
    }

    public function payouts()
    {
        return $this->hasMany(PartnerPayout::class);
    }
}
