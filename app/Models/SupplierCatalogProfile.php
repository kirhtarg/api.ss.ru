<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierCatalogProfile extends Model
{
    protected $fillable = [
        'code',
        'name',
        'supplier_names',
        'settings',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'supplier_names' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
