<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerApiRequestLog extends Model
{
    protected $fillable = ['partner_id', 'request_id', 'method', 'path', 'response_status', 'duration_ms', 'ip_address', 'request_hash', 'error_code'];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
