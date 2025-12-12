<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopCategoryExtraMenu extends Model
{
    use HasFactory;

    protected $table = 'shop_category_extra_menus';

    protected $fillable = [
        'category_id',
        'is_active',
        'title'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    protected $attributes = [
        'is_active' => false
    ];

    /**
     * Категория, к которой относится экстра-меню
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ShopCategory::class, 'category_id');
    }

    /**
     * Фильтры экстра-меню
     */
    public function filters(): HasMany
    {
        return $this->hasMany(ShopCategoryExtraMenuFilter::class, 'extra_menu_id')->orderBy('sort_order');
    }

    /**
     * Подразделы экстра-меню
     */
    public function sections(): HasMany
    {
        return $this->hasMany(ShopCategoryExtraMenuSection::class, 'extra_menu_id')->orderBy('sort_order');
    }
}




