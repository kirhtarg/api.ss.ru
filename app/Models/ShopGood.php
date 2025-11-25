<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use App\Models\Shop\Property as ShopProperty;

class ShopGood extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'sku',
        'description',
        'short_description',
        'price',
        'sale_price',
        'stock_quantity',
        'remote_stock_quantity',
        'width',
        'height',
        'depth', // В базе данных поле называется depth, но через accessor доступно как length
        'weight',
        'rating',
        'reviews_count',
        'meta_title',
        'meta_description',
        'is_active',
        'is_featured',
        'is_new',
        'is_sale',
        'sort_order'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'remote_stock_quantity' => 'string', // Может быть строкой типа ">10", поэтому не приводим к integer
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'depth' => 'decimal:2', // В базе данных поле называется depth, но через accessor доступно как length
        'weight' => 'decimal:2',
        'rating' => 'decimal:2',
        'reviews_count' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'is_new' => 'boolean',
        'is_sale' => 'boolean',
        'sort_order' => 'integer'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($good) {
            if (empty($good->slug)) {
                $good->slug = Str::slug($good->name);
            }
        });

        static::updating(function ($good) {
            if ($good->isDirty('name') && empty($good->slug)) {
                $good->slug = Str::slug($good->name);
            }
        });
    }

    /**
     * Категории товара
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ShopCategory::class, 'shop_good_categories', 'good_id', 'category_id');
    }

    /**
     * Бренды товара
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(ShopBrand::class, 'shop_good_brands', 'good_id', 'brand_id');
    }

    /**
     * Теги товара
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ShopTag::class, 'shop_good_tags', 'good_id', 'tag_id');
    }

    /**
     * Свойства товара
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(ShopProperty::class, 'shop_good_properties', 'good_id', 'property_id')
            ->withPivot('shop_property_value_id')
            ->withTimestamps();
    }

    /**
     * Вариации товара
     */
    public function variations(): HasMany
    {
        return $this->hasMany(ShopGoodVariation::class, 'good_id')->ordered();
    }

    /**
     * Изображения товара (только товара, без вариаций)
     */
    public function images(): HasMany
    {
        return $this->hasMany(ShopGoodImage::class, 'good_id')
            ->whereNull('variation_id')
            ->ordered();
    }

    /**
     * Все изображения (товара + вариаций)
     */
    public function allImages(): HasMany
    {
        return $this->hasMany(ShopGoodImage::class, 'good_id')->ordered();
    }

    /**
     * Видео товара (только для товара, не для вариаций)
     */
    public function videos(): HasMany
    {
        return $this->hasMany(ShopGoodVideo::class, 'good_id')->whereNull('variation_id');
    }

    /**
     * Все видео товара (включая вариации)
     */
    public function allVideos(): HasMany
    {
        return $this->hasMany(ShopGoodVideo::class, 'good_id');
    }


    /**
     * Остатки товара
     */
    public function stock(): HasMany
    {
        return $this->hasMany(ShopStock::class, 'good_id');
    }

    /**
     * Цены товара
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ShopGoodPrice::class, 'good_id');
    }

    /**
     * Аудит товара
     */
    public function audit(): HasMany
    {
        return $this->hasMany(ShopGoodAudit::class, 'good_id');
    }

    /**
     * Главное изображение
     */
    public function mainImage(): HasOne
    {
        return $this->hasOne(ShopGoodImage::class, 'good_id')->where('is_main', true);
    }

    /**
     * Scope для активных товаров
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для рекомендуемых товаров
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope для новых товаров
     */
    public function scopeNew($query)
    {
        return $query->where('is_new', true);
    }

    /**
     * Scope для товаров со скидкой
     */
    public function scopeSale($query)
    {
        return $query->where('is_sale', true);
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Scope для поиска по названию, SKU, описанию
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('sku', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%")
              ->orWhere('short_description', 'like', "%{$search}%");
        });
    }

    /**
     * Scope для фильтрации по цене
     */
    public function scopePriceRange($query, $minPrice, $maxPrice)
    {
        // Преобразуем значения в числа, если они не null
        $minPriceNum = ($minPrice !== null && $minPrice !== '') ? (float)$minPrice : null;
        $maxPriceNum = ($maxPrice !== null && $maxPrice !== '') ? (float)$maxPrice : null;
        
        // Если оба значения заданы и они одинаковы (с учетом погрешности), это точное значение
        if ($minPriceNum !== null && $maxPriceNum !== null && abs($minPriceNum - $maxPriceNum) < 0.01) {
            $query->where('price', '=', $maxPriceNum);
        } else {
            // Применяем диапазон
            if ($minPriceNum !== null) {
                $query->where('price', '>=', $minPriceNum);
            }
            if ($maxPriceNum !== null) {
                $query->where('price', '<=', $maxPriceNum);
            }
        }
        
        return $query;
    }

    /**
     * Scope для фильтрации по рейтингу
     */
    public function scopeRating($query, $minRating)
    {
        return $query->where('rating', '>=', $minRating);
    }

    /**
     * Scope для фильтрации по наличию
     */
    public function scopeInStock($query)
    {
        return $query->where('stock_quantity', '>', 0);
    }

    /**
     * Scope для фильтрации по категории
     */
    public function scopeByCategory($query, $categoryId)
    {
        return $query->whereHas('categories', function ($q) use ($categoryId) {
            $q->where('shop_categories.id', $categoryId);
        });
    }

    /**
     * Scope для фильтрации по бренду
     */
    public function scopeByBrand($query, $brandId)
    {
        return $query->whereHas('brands', function ($q) use ($brandId) {
            $q->where('shop_brands.id', $brandId);
        });
    }

    /**
     * Scope для фильтрации по тегу
     */
    public function scopeByTag($query, $tagId)
    {
        return $query->whereHas('tags', function ($q) use ($tagId) {
            $q->where('shop_tags.id', $tagId);
        });
    }

    /**
     * Получить финальную цену (с учетом скидки)
     */
    public function getFinalPriceAttribute()
    {
        return $this->sale_price ?: $this->price;
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
     * Accessor для length - использует length или depth для обратной совместимости
     */
    public function getLengthAttribute($value)
    {
        // Если length есть в атрибутах, возвращаем его
        if (isset($this->attributes['length']) && $this->attributes['length'] !== null) {
            return $this->attributes['length'];
        }
        // Иначе возвращаем depth для обратной совместимости
        return $this->attributes['depth'] ?? null;
    }

    /**
     * Получить габариты в виде строки
     */
    public function getDimensionsAttribute()
    {
        $dimensions = [];
        if ($this->width) $dimensions[] = $this->width . '×';
        if ($this->height) $dimensions[] = $this->height . '×';
        $length = $this->length ?? $this->depth ?? null;
        if ($length) $dimensions[] = $length;

        return implode('', $dimensions) ?: null;
    }

    /**
     * Характеристики товара
     */
    // public function characteristics(): HasMany
    // {
    //     return $this->hasMany(ShopGoodCharacteristic::class, 'good_id');
    // }

    /**
     * Получить главное изображение или первое доступное
     */
    public function getMainImageAttribute()
    {
        if (!$this->relationLoaded('images')) {
            return null;
        }

        // Ищем главное изображение
        $mainImage = $this->images->where('is_main', true)->first();

        // Если главного нет, берем первое
        if (!$mainImage) {
            $mainImage = $this->images->sortBy('sort_order')->first();
        }

        return $mainImage;
    }
}
