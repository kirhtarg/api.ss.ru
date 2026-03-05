<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopVariationAttribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
    ];

    protected $casts = [
        // Убираем casts для несуществующих полей
    ];

    protected static function boot()
    {
        parent::boot();
        // Убираем автоматическое создание slug, так как поле не существует в таблице
    }

    /**
     * Значения этого атрибута
     */
    public function values(): HasMany
    {
        return $this->hasMany(ShopVariationAttributeValue::class, 'attribute_id');
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }
}
