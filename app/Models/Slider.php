<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Slider extends Model
{
    protected $fillable = [
        'name',
        'transition_type',
        'control_type',
        'auto_interval',
        'transition_duration',
        'title_position',
        'text_position',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_interval' => 'integer',
        'transition_duration' => 'integer',
        'sort_order' => 'integer'
    ];

    /**
     * Связь с изображениями слайдера
     */
    public function images(): HasMany
    {
        return $this->hasMany(SliderImage::class)->orderBy('sort_order');
    }

    /**
     * Активные изображения слайдера
     */
    public function activeImages(): HasMany
    {
        return $this->hasMany(SliderImage::class)->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Scope для активных слайдеров
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
}
