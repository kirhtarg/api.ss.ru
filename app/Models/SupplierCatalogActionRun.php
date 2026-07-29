<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCatalogActionRun extends Model
{
    protected $fillable = [
        'snapshot_id', 'user_id', 'supplier_code', 'scope', 'action', 'status',
        'selection', 'backup', 'result', 'error_message', 'executed_at',
    ];

    protected $casts = [
        'selection' => 'array',
        'backup' => 'array',
        'result' => 'array',
        'executed_at' => 'datetime',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalogSnapshot::class, 'snapshot_id');
    }
}
