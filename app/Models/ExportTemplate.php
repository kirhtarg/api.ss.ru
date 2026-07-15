<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportTemplate extends Model
{
    protected $fillable = [
        'created_by',
        'entity_name',
        'name',
        'configuration',
    ];

    protected $casts = [
        'configuration' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
