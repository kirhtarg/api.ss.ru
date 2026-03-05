<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PageSettingsTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'settings',
        'structure',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'settings' => 'array',
        'structure' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Scope для активных шаблонов
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
