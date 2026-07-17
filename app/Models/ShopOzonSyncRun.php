<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopOzonSyncRun extends Model
{
    protected $fillable = ['account_id', 'user_id', 'parent_run_id', 'mode', 'status', 'good_ids', 'mapping_ids', 'binding_ids', 'total', 'processed', 'succeeded', 'failed', 'error_message', 'started_at', 'finished_at'];
    protected $casts = ['good_ids' => 'array', 'mapping_ids' => 'array', 'binding_ids' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    public function items(): HasMany { return $this->hasMany(ShopOzonSyncItem::class, 'run_id'); }
}
