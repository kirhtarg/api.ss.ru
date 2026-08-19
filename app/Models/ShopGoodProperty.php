<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopGoodProperty extends Model
{
    use HasFactory;

    protected $table = 'shop_good_properties';

    protected $fillable = [
        'good_id',
        'variation_id',
        'property_id',
        'shop_property_value_id',
        'value',
    ];

    protected $casts = [
        'good_id' => 'integer',
        'variation_id' => 'integer',
        'property_id' => 'integer',
        'shop_property_value_id' => 'integer',
    ];

    protected $nullable = [
        'good_id',
        'shop_property_value_id',
    ];

    /**
     * Товар (может быть null для вариаций)
     */
    public function good(): BelongsTo
    {
        return $this->belongsTo(ShopGood::class, 'good_id');
    }

    /**
     * Вариация товара
     */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(ShopGoodVariation::class, 'variation_id');
    }

    /**
     * Свойство (если есть отдельная таблица свойств)
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(ShopProperty::class, 'property_id');
    }

    /**
     * Значение свойства из справочника
     */
    public function propertyValue(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Shop\PropertyValue::class, 'shop_property_value_id');
    }

    /**
     * Получить значение свойства
     */
    public function getValueAttribute()
    {
        return $this->propertyValue ? $this->propertyValue->value : null;
    }
}
