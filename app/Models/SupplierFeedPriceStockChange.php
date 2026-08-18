<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierFeedPriceStockChange extends Model
{
    protected $fillable = [
        'run_id', 'sku', 'entity_type', 'good_id', 'variation_id', 'good_name',
        'field', 'before_value', 'after_value', 'is_applied',
    ];

    protected function casts(): array
    {
        return ['is_applied' => 'boolean'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SupplierFeedPriceStockRun::class, 'run_id');
    }
}
