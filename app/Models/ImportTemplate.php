<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'settings',
        'is_default'
    ];

    protected $casts = [
        'settings' => 'array',
        'is_default' => 'boolean'
    ];

    /**
     * Получить шаблон по умолчанию
     */
    public static function getDefault()
    {
        return static::where('is_default', true)->first();
    }

    /**
     * Установить как шаблон по умолчанию
     */
    public function setAsDefault()
    {
        // Снимаем флаг с других шаблонов
        static::where('is_default', true)->update(['is_default' => false]);
        
        // Устанавливаем флаг для текущего
        $this->update(['is_default' => true]);
    }

    /**
     * Получить все шаблоны кроме текущего
     */
    public static function getOthers($excludeId = null)
    {
        $query = static::query();
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->orderBy('name')->get();
    }

    /**
     * Проверить, является ли шаблон используемым
     */
    public function isInUse()
    {
        // Здесь можно добавить логику проверки использования
        // Например, проверить, есть ли активные импорты с этим шаблоном
        return false;
    }
}
