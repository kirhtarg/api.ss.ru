<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'folder_name',
        'is_active',
        'is_active_card',
        'is_active_page',
        'settings',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_active_card' => 'boolean',
        'is_active_page' => 'boolean',
        'settings' => 'array',
    ];

    /**
     * Получить активные шаблоны магазина
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Получить шаблоны магазина отсортированные по порядку
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Получить активный шаблон магазина
     */
    public static function getActive()
    {
        return static::active()->first();
    }

    /**
     * Получить активный шаблон для карточек товаров
     */
    public static function getActiveCard()
    {
        return static::where('is_active_card', true)->first();
    }

    /**
     * Получить активный шаблон для страниц товаров
     */
    public static function getActivePage()
    {
        return static::where('is_active_page', true)->first();
    }

    /**
     * Получить дефолтный шаблон магазина
     */
    public static function getDefault()
    {
        return static::where('folder_name', 'default')->first();
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
            'is_active' => $this->is_active,
            'settings' => $this->settings,
        ];
    }
}
