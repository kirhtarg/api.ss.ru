<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopLabel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'color',
        'sort_order'
    ];

    protected $casts = [
        'sort_order' => 'integer'
    ];

    /**
     * Товары с этим лейблом
     */
    public function goods(): HasMany
    {
        return $this->hasMany(ShopGood::class, 'label_id');
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}

