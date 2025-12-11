<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopCategory extends Model
{
    use HasFactory;

    protected $table = 'shop_categories';

    protected $fillable = [
        'name',
        'description',
        'image',
        'icon',
        'slug',
        'is_active',
        'is_main',
        'in_catalog',
        'in_figure',
        'in_figure_img',
        'in_figure_text',
        'sort_order',
        'parent_id'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_main' => 'boolean',
        'in_catalog' => 'boolean',
        'in_figure' => 'boolean',
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

    // Отношение к экстра-меню
    public function extraMenu()
    {
        return $this->hasOne(ShopCategoryExtraMenu::class, 'category_id');
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

    /**
     * Получить все дочерние категории рекурсивно (включая вложенные)
     * @param array $categoryIds Массив ID категорий
     * @return array Массив ID всех категорий (включая переданные и все их дочерние)
     */
    public static function getAllDescendantIds(array $categoryIds): array
    {
        if (empty($categoryIds)) {
            return [];
        }

        $allCategoryIds = array_unique($categoryIds);
        $processedIds = [];
        
        // Рекурсивно находим все дочерние категории
        while (count($allCategoryIds) > count($processedIds)) {
            $idsToProcess = array_diff($allCategoryIds, $processedIds);
            
            if (empty($idsToProcess)) {
                break;
            }
            
            // Получаем прямых потомков для текущих категорий
            $children = self::whereIn('parent_id', $idsToProcess)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
            
            // Добавляем найденных потомков в общий список
            foreach ($children as $childId) {
                if (!in_array($childId, $allCategoryIds)) {
                    $allCategoryIds[] = $childId;
                }
            }
            
            // Помечаем обработанные категории
            $processedIds = array_merge($processedIds, $idsToProcess);
        }
        
        return array_values($allCategoryIds);
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
