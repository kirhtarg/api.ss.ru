<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopDeliveryMethod extends Model
{
    use HasFactory;

    protected $table = 'shop_delivery_methods';

    protected $fillable = [
        'name',
        'type',
        'is_active',
        'cost',
        'free_from',
        'description',
        'settings',
        'sort_order',
        'is_default'
    ];


    protected $casts = [
        'is_active' => 'boolean',
        'cost' => 'decimal:2',
        'free_from' => 'decimal:2',
        'settings' => 'array',
        'is_default' => 'boolean'
    ];

    /**
     * Scope для активных способов доставки
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
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Получить способ доставки по умолчанию
     */
    public static function getDefault()
    {
        return static::where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * Проверить, доступна ли бесплатная доставка
     */
    public function isFreeDelivery($orderAmount)
    {
        return $this->free_from && $orderAmount >= $this->free_from;
    }

    /**
     * Получить стоимость доставки для заказа
     */
    public function getDeliveryCost($orderAmount)
    {
        if ($this->isFreeDelivery($orderAmount)) {
            return 0;
        }
        
        return $this->cost;
    }
}
