<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShopCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'shop_categories';

    protected $fillable = [
        'name',
        'description',
        'image',
        'icon',
        'slug',
        'is_active',
        'is_main',
        'sort_order',
        'parent_id'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_main' => 'boolean',
        'sort_order' => 'integer',
        'parent_id' => 'integer'
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0
    ];

    // Отношение к родительской категории
    public function parent()
    {
        return $this->belongsTo(ShopCategory::class, 'parent_id');
    }

    // Отношение к дочерним категориям
    public function children()
    {
        return $this->hasMany(ShopCategory::class, 'parent_id');
    }

    // Отношение к товарам через промежуточную таблицу
    public function goods()
    {
        return $this->belongsToMany(\App\Models\ShopGood::class, 'shop_good_categories', 'category_id', 'good_id');
    }

    // Scope для активных категорий
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Scope для главных категорий
    public function scopeMain($query)
    {
        return $query->where('is_main', true);
    }

    // Scope для сортировки
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('name', 'asc');
    }

    // Автоматическое создание slug из названия (если не передан)
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = \Illuminate\Support\Str::slug($category->name);
            }
        });

        static::updating(function ($category) {
            if ($category->isDirty('name') && empty($category->slug)) {
                $category->slug = \Illuminate\Support\Str::slug($category->name);
            }
        });
    }
}
