<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierCatalogSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_code', 'source_type', 'source_name', 'source_url', 'storage_path',
        'checksum', 'status', 'progress', 'stage', 'processed_rows', 'total_rows', 'items_count', 'summary', 'error_message',
        'source_updated_at', 'processed_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'source_updated_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SupplierCatalogItem::class, 'snapshot_id');
    }

    public function imageAudits(): HasMany
    {
        return $this->hasMany(SupplierCatalogImageAudit::class, 'snapshot_id');
    }
}
