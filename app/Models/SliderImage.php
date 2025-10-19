<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SliderImage extends Model
{
    protected $fillable = [
        'slider_id',
        'image_path',
        'title',
        'text',
        'link',
        'link_type',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    /**
     * Связь со слайдером
     */
    public function slider(): BelongsTo
    {
        return $this->belongsTo(Slider::class);
    }

    /**
     * Scope для активных изображений
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
        return $query->orderBy('sort_order');
    }

    /**
     * Получить полный URL изображения
     */
    public function getImageUrlAttribute(): string
    {
        return asset('sliders/' . $this->image_path);
    }
}
