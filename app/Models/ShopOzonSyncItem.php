<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopOzonSyncItem extends Model
{
    protected $fillable = ['run_id', 'good_id', 'variation_id', 'offer_id', 'status', 'task_id', 'request_payload', 'response_payload', 'errors'];
    protected $casts = ['request_payload' => 'array', 'response_payload' => 'array', 'errors' => 'array'];
}
