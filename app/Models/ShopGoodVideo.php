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
        'title',
        'thumbnail',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected $appends = [
        'url',
        'embed_url',
        'is_external',
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
            // Если это уже полный URL, возвращаем как есть
            if (str_starts_with($this->video_path, 'http')) {
                return $this->video_path;
            }

            // Убираем лишний префикс videos/ если он уже есть
            $cleanPath = ltrim($this->video_path, '/');
            if (str_starts_with($cleanPath, 'videos/')) {
                return '/'.$cleanPath;
            }

            // Возвращаем путь к файлу в папке public/videos/
            return '/videos/'.$cleanPath;
        }

        return null;
    }

    /**
     * Получить URL превью
     */
    public function getThumbnailUrlAttribute()
    {
        if ($this->thumbnail) {
            // Если это уже полный URL, возвращаем как есть
            if (str_starts_with($this->thumbnail, 'http')) {
                return $this->thumbnail;
            }

            // Убираем лишний префикс images/ если он уже есть
            $cleanPath = ltrim($this->thumbnail, '/');
            if (str_starts_with($cleanPath, 'images/')) {
                return '/'.$cleanPath;
            }

            // Возвращаем путь к файлу в папке public/images/
            return '/images/'.$cleanPath;
        }

        return null;
    }

    /**
     * Проверить, является ли видео внешним
     */
    public function getIsExternalAttribute()
    {
        return ! empty($this->external_url);
    }

    /**
     * Получить embed URL для внешних видео
     */
    public function getEmbedUrlAttribute()
    {
        if (! $this->external_url) {
            return null;
        }

        // YouTube
        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]+)/', $this->external_url, $matches)) {
            return 'https://www.youtube.com/embed/'.$matches[1].'?autoplay=0';
        }

        // Vimeo
        if (preg_match('/vimeo\.com\/(\d+)/', $this->external_url, $matches)) {
            return 'https://player.vimeo.com/video/'.$matches[1].'?autoplay=0';
        }

        // VK
        if (preg_match('/vk\.com\/video(-?\d+_\d+)/', $this->external_url, $matches)) {
            return 'https://vk.com/video_ext.php?oid='.$matches[1].'&autoplay=0';
        }

        // VK Video (vkvideo.ru)
        if (preg_match('/vkvideo\.ru\/(video|clip)(-?\d+)_(\d+)/', $this->external_url, $matches)) {
            $oid = $matches[2]; // -178294909
            $id = $matches[3];  // 456240059

            return "https://vkvideo.ru/video_ext.php?oid={$oid}&id={$id}&hd=2&autoplay=0";
        }

        // Rutube
        if (preg_match('/rutube\.ru\/video\/([a-zA-Z0-9]+)/', $this->external_url, $matches)) {
            return 'https://rutube.ru/play/embed/'.$matches[1].'?autoplay=0';
        }

        return $this->external_url;
    }
}
