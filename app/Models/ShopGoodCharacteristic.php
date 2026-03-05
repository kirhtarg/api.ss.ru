<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopGoodCharacteristic extends Model
{
    use HasFactory;

    protected $table = 'shop_good_characteristics';

    protected $fillable = [
        'good_id',
        'name',
        'value',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'sort_order' => 0,
    ];

    /**
     * Товар, к которому относится характеристика
     */
    public function good()
    {
        return $this->belongsTo(ShopGood::class, 'good_id');
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('name', 'asc');
    }
}
