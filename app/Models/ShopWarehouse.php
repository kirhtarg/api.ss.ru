<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopWarehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'address',
        'phone',
        'email',
        'is_active',
        'is_default',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer'
    ];

    /**
     * Остатки на этом складе
     */
    public function stock(): HasMany
    {
        return $this->hasMany(ShopStock::class);
    }

    /**
     * Резервирования на этом складе
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(ShopStockReservation::class);
    }

    /**
     * Scope для активных складов
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
     * Scope для получения склада по умолчанию
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
