<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConstructorBlockSetting extends Model
{
    use HasFactory;

    protected $table = 'constructor_block_settings';

    protected $fillable = [
        'block_type',
        'block_name',
        'category',
        'icon',
        'default_settings',
        'available_settings',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'default_settings' => 'array',
        'available_settings' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    /**
     * Scope для активных блоков
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
     * Получить блоки по категориям
     */
    public static function getGroupedByCategory()
    {
        return static::active()
            ->ordered()
            ->get()
            ->groupBy('category');
    }

    /**
     * Найти настройки блока по типу
     */
    public static function findByType($type)
    {
        return static::where('block_type', $type)->first();
    }
}