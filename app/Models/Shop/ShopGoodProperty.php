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
        'value'
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
}
