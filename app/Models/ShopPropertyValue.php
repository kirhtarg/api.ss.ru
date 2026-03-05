<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopPropertyValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'value',
        'color',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'property_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Свойство, к которому относится значение
     */
    public function property()
    {
        return $this->belongsTo(ShopProperty::class, 'property_id');
    }

    /**
     * Scope для активных значений
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
        return $query->orderBy('sort_order')->orderBy('value');
    }
}
