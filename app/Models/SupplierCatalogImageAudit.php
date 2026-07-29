<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCatalogImageAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_id',
        'source_url',
        'source_url_hash',
        'status',
        'content_hash',
        'perceptual_hash',
        'mime_type',
        'width',
        'height',
        'byte_size',
        'error_message',
        'checked_at',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'byte_size' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalogSnapshot::class, 'snapshot_id');
    }
}
