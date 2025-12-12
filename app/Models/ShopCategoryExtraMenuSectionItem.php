<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopCategoryExtraMenuSectionItem extends Model
{
    use HasFactory;

    protected $table = 'shop_category_extra_menu_section_items';

    protected $fillable = [
        'section_id',
        'category_id',
        'sort_order'
    ];

    protected $casts = [
        'sort_order' => 'integer'
    ];

    protected $attributes = [
        'sort_order' => 0
    ];

    /**
     * Подраздел, к которому относится элемент
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ShopCategoryExtraMenuSection::class, 'section_id');
    }

    /**
     * Категория (подкатегория)
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ShopCategory::class, 'category_id');
    }
}






