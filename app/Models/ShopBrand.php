<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ShopBrand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'logo',
        'slug',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($brand) {
            if (empty($brand->slug)) {
                $brand->slug = Str::slug($brand->name);
            }
            
            // Проверяем уникальность slug и добавляем суффикс если нужно
            $originalSlug = $brand->slug;
            $counter = 1;
            while (static::where('slug', $brand->slug)->exists()) {
                $brand->slug = $originalSlug . '-' . $counter;
                $counter++;
            }
        });

        static::updating(function ($brand) {
            if ($brand->isDirty('name') && empty($brand->slug)) {
                $brand->slug = Str::slug($brand->name);
            }
            
            // Проверяем уникальность slug при обновлении
            if ($brand->isDirty('slug')) {
                $originalSlug = $brand->slug;
                $counter = 1;
                while (static::where('slug', $brand->slug)->where('id', '!=', $brand->id)->exists()) {
                    $brand->slug = $originalSlug . '-' . $counter;
                    $counter++;
                }
            }
        });
    }

    /**
     * Товары этого бренда
     */
    public function goods(): BelongsToMany
    {
        return $this->belongsToMany(ShopGood::class, 'shop_good_brands');
    }

    /**
     * Scope для активных брендов
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
