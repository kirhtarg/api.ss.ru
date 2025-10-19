<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminMenuItem extends Model
{
    protected $fillable = [
        'page_id',
        'parent_id',
        'icon',
        'label',
        'href',
        'description',
        'order',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer'
    ];

    /**
     * Отношение к странице
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(AdminPage::class, 'page_id');
    }

    /**
     * Отношение к родительскому пункту меню
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(AdminMenuItem::class, 'parent_id');
    }

    /**
     * Получить активные пункты меню
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Отсортировать по порядку
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
