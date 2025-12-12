<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopCategoryExtraMenuSection extends Model
{
    use HasFactory;

    protected $table = 'shop_category_extra_menu_sections';

    protected $fillable = [
        'extra_menu_id',
        'title',
        'sort_order'
    ];

    protected $casts = [
        'sort_order' => 'integer'
    ];

    protected $attributes = [
        'sort_order' => 0
    ];

    /**
     * Экстра-меню, к которому относится подраздел
     */
    public function extraMenu(): BelongsTo
    {
        return $this->belongsTo(ShopCategoryExtraMenu::class, 'extra_menu_id');
    }

    /**
     * Элементы подраздела (подкатегории)
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShopCategoryExtraMenuSectionItem::class, 'section_id')->orderBy('sort_order');
    }
}






