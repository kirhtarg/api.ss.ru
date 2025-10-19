<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopGoodProperty extends Model
{
    use HasFactory;

    protected $table = 'shop_good_properties';

    protected $fillable = [
        'good_id',
        'property_id',
        'shop_property_value_id'
    ];

    /**
     * Связь с товаром
     */
    public function good(): BelongsTo
    {
        return $this->belongsTo(ShopGood::class, 'good_id');
    }

    /**
     * Связь со свойством
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /**
     * Связь со значением свойства из справочника
     */
    public function propertyValue(): BelongsTo
    {
        return $this->belongsTo(PropertyValue::class, 'shop_property_value_id');
    }

    /**
     * Получить значение свойства
     */
    public function getValueAttribute()
    {
        return $this->propertyValue ? $this->propertyValue->value : null;
    }
}
