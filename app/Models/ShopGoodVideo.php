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
        'external_url',
        'file_path',
        'title',
        'description',
        'duration',
        'thumbnail',
        'sort_order'
    ];

    protected $casts = [
        'duration' => 'integer',
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
     * Получить полный URL видео
     */
    public function getUrlAttribute()
    {
        if ($this->external_url) {
            return $this->external_url;
        }
        
        if ($this->video_path) {
            return asset('storage/' . $this->video_path);
        }
        
        if ($this->file_path) {
            return asset('storage/' . $this->file_path);
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
