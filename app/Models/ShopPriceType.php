<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopPriceType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'multiplier',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'multiplier' => 'decimal:4',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Цены этого типа
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ShopGoodPrice::class);
    }

    /**
     * Scope для активных типов цен
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

    /**
     * Scope для получения типа цены по умолчанию
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
