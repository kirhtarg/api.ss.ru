<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyValue extends Model
{
    use HasFactory;

    protected $table = 'shop_property_values';

    protected $fillable = [
        'property_id',
        'value',
        'color',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Свойство, к которому принадлежит значение
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Получить цвет в формате HEX
     */
    public function getColorHexAttribute(): ?string
    {
        return $this->color ? '#' . ltrim($this->color, '#') : null;
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('value');
    }
}
