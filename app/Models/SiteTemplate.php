<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'folder_name',
        'menu_template_id',
        'auth_template_id',
        'is_active',
        'settings',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    /**
     * Связь с шаблоном меню
     */
    public function menuTemplate(): BelongsTo
    {
        return $this->belongsTo(SiteMenu::class, 'menu_template_id');
    }

    /**
     * Связь с шаблоном блока авторизации
     */
    public function authTemplate(): BelongsTo
    {
        return $this->belongsTo(SiteAuthBlock::class, 'auth_template_id');
    }

    /**
     * Получить активный шаблон сайта
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Получить шаблоны сайта отсортированные по порядку
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Получить активный шаблон сайта
     */
    public static function getActive()
    {
        return static::active()->with(['menuTemplate', 'authTemplate'])->first();
    }

    /**
     * Получить дефолтный шаблон сайта
     */
    public static function getDefault()
    {
        return static::where('folder_name', 'default')->with(['menuTemplate', 'authTemplate'])->first();
    }

    /**
     * Активировать шаблон (деактивировать все остальные)
     */
    public function activate()
    {
        // Деактивируем все шаблоны
        static::query()->update(['is_active' => false]);
        
        // Активируем текущий
        $this->update(['is_active' => true]);
    }

    /**
     * Получить данные шаблона для фронтенда
     */
    public function getTemplateData()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'folder_name' => $this->folder_name,
            'menu_template' => $this->menuTemplate?->template_name ?? 'default',
            'auth_template' => $this->authTemplate?->template_name ?? 'default',
            'is_active' => $this->is_active,
            'settings' => $this->settings,
        ];
    }
}
