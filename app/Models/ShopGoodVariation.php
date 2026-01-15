<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopGoodVariation extends Model
{
    use HasFactory;

    protected $fillable = [
        'good_id',
        'supplier',
        'name',
        'sku',
        'price',
        'sale_price',
        'demping_price',
        'show_demping',
        'stock_quantity',
        'remote_stock_quantity',
        'fast_remote_stock_quantity',
        'weight',
        'length',
        'height',
        'width',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'demping_price' => 'decimal:2',
        'show_demping' => 'boolean',
        'stock_quantity' => 'integer',
        'weight' => 'decimal:2',
        'length' => 'decimal:2',
        'height' => 'decimal:2',
        'width' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    /**
     * Основной товар
     */
    public function good(): BelongsTo
    {
        return $this->belongsTo(ShopGood::class, 'good_id');
    }

    // Связи со старыми свойствами удалены; вариационные атрибуты берутся из таблиц
    // shop_variation_attributes_values -> shop_variation_attribute_values -> shop_variation_attributes

    /**
     * Изображения вариации
     */
    public function images(): HasMany
    {
        return $this->hasMany(ShopGoodImage::class, 'variation_id');
    }

    /**
     * Видео вариации
     */
    public function videos(): HasMany
    {
        return $this->hasMany(ShopGoodVideo::class, 'variation_id');
    }

    /**
     * Остатки вариации (временно отключено)
     */
    // public function stock(): HasMany
    // {
    //     return $this->hasMany(ShopStock::class, 'variation_id');
    // }

    /**
     * Цены вариации (временно отключено)
     */
    // public function prices(): HasMany
    // {
    //     return $this->hasMany(ShopGoodPrice::class, 'variation_id');
    // }

    /**
     * Scope для активных вариаций
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
        return $query->orderBy('name');
    }

    /**
     * Получить финальную цену (с учетом скидки и демпинга)
     * Приоритет: демпинговая (если show_demping = true) > акционная > базовая
     */
    public function getFinalPriceAttribute()
    {
        // Если демпинг активирован и есть демпинговая цена, используем её
        if ($this->show_demping && $this->demping_price && $this->demping_price > 0) {
            return $this->demping_price;
        }
        
        // Если есть акционная цена, используем её
        if ($this->sale_price && $this->sale_price > 0 && $this->sale_price < $this->price) {
            return $this->sale_price;
        }
        
        // Иначе базовая цена
        return $this->price;
    }

    /**
     * Получить размер скидки в процентах
     */
    public function getDiscountPercentAttribute()
    {
        if (!$this->sale_price || $this->sale_price >= $this->price) {
            return 0;
        }
        
        return round((($this->price - $this->sale_price) / $this->price) * 100);
    }

    /**
     * Получить название вариации (если не задано, то из основного товара)
     */
    public function getDisplayNameAttribute()
    {
        return $this->name ?: $this->good->name;
    }

    /**
     * Получить габариты в виде строки
     */
    public function getDimensionsAttribute()
    {
        $dimensions = [];
        if ($this->width) $dimensions[] = $this->width . '×';
        if ($this->height) $dimensions[] = $this->height . '×';
        if ($this->length) $dimensions[] = $this->length;
        
        return implode('', $dimensions) ?: null;
    }

    /**
     * Получить атрибуты вариации в виде строки
     */
    public function getAttributesStringAttribute()
    {
        // Собираем строку атрибутов вариации из новой схемы
        $rows = \Illuminate\Support\Facades\DB::table('shop_variation_attributes_values as vav')
            ->join('shop_variation_attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->join('shop_variation_attributes as a', 'a.id', '=', 'av.attribute_id')
            ->where('vav.variation_id', $this->id)
            ->select('a.name as attribute_name', 'av.value as value_value')
            ->orderBy('a.name')
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        return $rows->map(function ($r) {
            return ($r->attribute_name ?? 'Attribute') . ': ' . ($r->value_value ?? '');
        })->join(', ');
    }
}
