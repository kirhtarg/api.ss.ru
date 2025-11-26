<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ShopProperty extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'property_type',
        'sort_order'
    ];

    protected $casts = [
        'sort_order' => 'integer'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($property) {
            if (empty($property->slug)) {
                $property->slug = Str::slug($property->name);
            }
        });

        static::updating(function ($property) {
            if ($property->isDirty('name') && empty($property->slug)) {
                $property->slug = Str::slug($property->name);
            }
        });
    }

    /**
     * Товары с этим свойством
     */
    public function goods(): BelongsToMany
    {
        return $this->belongsToMany(ShopGood::class, 'shop_good_properties', 'property_id', 'good_id')
            ->withPivot('shop_property_value_id')
            ->withTimestamps();
    }

    /**
     * Значения свойства (для типа select)
     */
    public function values()
    {
        return $this->hasMany(\App\Models\Shop\PropertyValue::class, 'property_id')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    /**
     * Scope для всех свойств (без фильтрации по активности)
     */
    public function scopeActive($query)
    {
        return $query; // Возвращаем все свойства
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
