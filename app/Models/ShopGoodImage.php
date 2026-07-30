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
        'source_content_hash',
        'content_hash',
        'perceptual_hash',
        'image_width',
        'image_height',
        'content_checked_at',
        'alt_text',
        'is_main',
        'sort_order',
    ];

    protected $casts = [
        'is_main' => 'boolean',
        'sort_order' => 'integer',
        'image_width' => 'integer',
        'image_height' => 'integer',
        'content_checked_at' => 'datetime',
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
        if (! $this->file_path) {
            return null;
        }

        // Если это уже полный URL, возвращаем как есть
        if (str_starts_with($this->file_path, 'http')) {
            return $this->file_path;
        }

        // Убираем лишний префикс images/ если он уже есть
        $cleanPath = ltrim($this->file_path, '/');
        if (str_starts_with($cleanPath, 'images/')) {
            return '/'.$cleanPath;
        }

        // Возвращаем путь к файлу в папке public/images/
        return '/images/'.$cleanPath;
    }
}
