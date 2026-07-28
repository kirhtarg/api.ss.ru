<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCatalogItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_id', 'external_sku', 'external_group_key', 'name', 'brand',
        'section', 'sub_section', 'price', 'opt_price', 'quantity', 'in_stock',
        'source_axes', 'image_urls', 'raw_payload',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'opt_price' => 'decimal:2',
        'quantity' => 'integer',
        'in_stock' => 'boolean',
        'source_axes' => 'array',
        'image_urls' => 'array',
        'raw_payload' => 'array',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalogSnapshot::class, 'snapshot_id');
    }
}
