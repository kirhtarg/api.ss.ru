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
        'property_id',
        'value'
    ];

    protected $casts = [
        'good_id' => 'integer',
        'property_id' => 'integer'
    ];

    /**
     * Товар
     */
    public function good(): BelongsTo
    {
        return $this->belongsTo(ShopGood::class, 'good_id');
    }

    /**
     * Свойство (если есть отдельная таблица свойств)
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(ShopProperty::class, 'property_id');
    }
}
