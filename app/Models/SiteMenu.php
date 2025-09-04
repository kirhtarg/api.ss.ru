<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteMenu extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'template_name',
        'is_active',
        'settings',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    /**
     * Связь с шаблонами сайта
     */
    public function siteTemplates(): HasMany
    {
        return $this->hasMany(SiteTemplate::class, 'menu_id');
    }

    /**
     * Связь с пунктами меню
     */
    public function menuItems(): HasMany
    {
        return $this->hasMany(SiteMenuItem::class, 'site_menu_id');
    }

    /**
     * Получить активные шаблоны меню
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Получить шаблоны меню отсортированные по порядку
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }
}
