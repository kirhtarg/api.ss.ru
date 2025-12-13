<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopCategoryExtraMenuFilter extends Model
{
    use HasFactory;

    protected $table = 'shop_category_extra_menu_filters';

    protected $fillable = [
        'extra_menu_id',
        'type',
        'is_active',
        'sort_order',
        'price_min',
        'price_max',
        'characteristic_name'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2'
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0
    ];

    /**
     * Экстра-меню, к которому относится фильтр
     */
    public function extraMenu(): BelongsTo
    {
        return $this->belongsTo(ShopCategoryExtraMenu::class, 'extra_menu_id');
    }
}













