<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AdminPage extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'title',
        'description',
        'icon',
        'component',
        'order',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer'
    ];

    /**
     * Отношение к пунктам меню
     */
    public function menuItems(): HasMany
    {
        return $this->hasMany(AdminMenuItem::class, 'page_id');
    }

    /**
     * Роли, которые имеют доступ к этой странице
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_page_role', 'admin_page_id', 'role_id');
    }

    /**
     * Получить активные страницы
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
