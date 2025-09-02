<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopGoodVideo extends Model
{
    use HasFactory;

    protected $fillable = [
        'good_id',
        'variation_id',
        'video_path',
        'video_url',
        'title',
        'description',
        'duration',
        'thumbnail',
        'is_main',
        'sort_order'
    ];

    protected $casts = [
        'duration' => 'integer',
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
     * Scope для главных видео
     */
    public function scopeMain($query)
    {
        return $query->where('is_main', true);
    }

    /**
     * Получить полный URL видео
     */
    public function getUrlAttribute()
    {
        if ($this->video_url) {
            return $this->video_url;
        }
        
        if ($this->video_path) {
            return asset('storage/' . $this->video_path);
        }
        
        return null;
    }

    /**
     * Получить URL превью
     */
    public function getThumbnailUrlAttribute()
    {
        if ($this->thumbnail) {
            return asset('storage/' . $this->thumbnail);
        }
        
        return null;
    }

    /**
     * Проверить, является ли видео внешним
     */
    public function getIsExternalAttribute()
    {
        return !empty($this->video_url);
    }
}
