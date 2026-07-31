<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerApiCredential extends Model
{
    protected $fillable = ['partner_id', 'key_id', 'secret', 'scopes', 'last_used_at', 'expires_at', 'revoked_at'];

    protected $hidden = ['secret'];

    protected $casts = ['secret' => 'encrypted', 'scopes' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function isUsable(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture()) && $this->partner?->is_active;
    }

    public function allows(string $scope): bool
    {
        return in_array('*', $this->scopes ?? [], true) || in_array($scope, $this->scopes ?? [], true);
    }
}
