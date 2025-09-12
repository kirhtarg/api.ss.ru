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
        'value'
    ];

    protected $casts = [
        'good_id' => 'integer',
        'variation_id' => 'integer',
        'property_id' => 'integer'
    ];

    protected $nullable = [
        'good_id'
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
}
