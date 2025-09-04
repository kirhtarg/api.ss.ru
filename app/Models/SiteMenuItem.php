<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteMenuItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_menu_id',
        'title',
        'url',
        'parent_id',
        'sort_order',
        'is_active',
        'target',
        'attributes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'attributes' => 'array',
    ];

    /**
     * Связь с меню
     */
    public function siteMenu(): BelongsTo
    {
        return $this->belongsTo(SiteMenu::class, 'site_menu_id');
    }

    /**
     * Связь с родительским пунктом меню
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(SiteMenuItem::class, 'parent_id');
    }

    /**
     * Связь с дочерними пунктами меню
     */
    public function children(): HasMany
    {
        return $this->hasMany(SiteMenuItem::class, 'parent_id')->ordered();
    }

    /**
     * Получить активные пункты меню
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Получить пункты меню отсортированные по порядку
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('title');
    }

    /**
     * Получить корневые пункты меню (без родителя)
     */
    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Получить все активные пункты меню с иерархией для конкретного меню
     */
    public static function getMenuTree($siteMenuId = null)
    {
        $query = static::active()->root()->ordered();
        
        if ($siteMenuId) {
            $query->where('site_menu_id', $siteMenuId);
        }
        
        return $query->with(['children' => function ($query) {
            $query->active()->ordered();
        }])->get();
    }

    /**
     * Получить данные пункта меню для фронтенда
     */
    public function getMenuData()
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'target' => $this->target,
            'attributes' => $this->attributes,
            'children' => $this->children->map(function ($child) {
                return $child->getMenuData();
            }),
        ];
    }
}
