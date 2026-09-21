<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopOzonDeliveryPointSyncRun extends Model
{
    protected $fillable = ['status', 'pages_synced', 'points_synced', 'total_points', 'error_message', 'requested_by', 'started_at', 'finished_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
