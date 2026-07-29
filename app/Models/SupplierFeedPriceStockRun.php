<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierFeedPriceStockRun extends Model
{
    protected $fillable = [
        'profile_id', 'status', 'trigger', 'offers_total', 'matched',
        'updated_prices', 'updated_stocks', 'unchanged', 'not_found',
        'summary', 'error_message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalogProfile::class, 'profile_id');
    }
}
