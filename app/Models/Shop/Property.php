<?php

namespace App\Models\Shop;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Property extends Model
{
    use HasFactory;

    protected $table = 'shop_properties';

    protected $fillable = [
        'name',
        'slug',
        'sort_order',
        'description',
        'property_type',
        'show_on_site',
    ];

    protected $casts = [
        'property_type' => 'string',
        'sort_order' => 'integer',
        'show_on_site' => 'boolean',
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
     * Значения свойства (для типа "выбор")
     */
    public function values(): HasMany
    {
        return $this->hasMany(PropertyValue::class);
    }

    /**
     * Получить активные значения
     */
    public function activeValues(): HasMany
    {
        return $this->values()->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Получить лейбл типа свойства
     */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->property_type) {
            'string' => 'Строка',
            'color' => 'Цвет',
            'select' => 'Выбор',
            default => 'Строка'
        };
    }

    /**
     * Связь с товарами через промежуточную таблицу
     */
    public function goodProperties(): HasMany
    {
        return $this->hasMany(ShopGoodProperty::class, 'property_id');
    }

    /**
     * Получить PropertyValue по ID из pivot
     */
    public function getPropertyValueById($id)
    {
        return $this->values()->find($id);
    }

    /**
     * Связь с категориями (многие-ко-многим)
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\ShopCategory::class, 'shop_category_property', 'property_id', 'category_id')
            ->withTimestamps();
    }
}
