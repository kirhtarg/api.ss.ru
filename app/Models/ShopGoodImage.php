<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopGoodImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'good_id',
        'variation_id',
        'file_path',
        'alt_text',
        'is_main',
        'sort_order'
    ];

    protected $casts = [
        'is_main' => 'boolean',
        'sort_order' => 'integer'
    ];

    /**
     * Товар
     */
    public function good(): BelongsTo
    {
        return $this->belongsTo(ShopGood::class, 'good_id');
    }

    /**
     * Вариация товара
     */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(ShopGoodVariation::class, 'variation_id');
    }

    /**
     * Scope для сортировки
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Scope для главных изображений
     */
    public function scopeMain($query)
    {
        return $query->where('is_main', true);
    }

    /**
     * Получить полный URL изображения
     */
    public function getUrlAttribute()
    {
        return asset('storage/' . $this->file_path);
    }
}
