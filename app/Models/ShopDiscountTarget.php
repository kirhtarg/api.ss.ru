<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopDiscountTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'discount_id',
        'target_type',
        'target_id',
    ];

    /**
     * Скидка
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(ShopDiscount::class, 'discount_id');
    }

    /**
     * Scope для определенного типа цели
     */
    public function scopeByTargetType($query, $type)
    {
        return $query->where('target_type', $type);
    }

    /**
     * Scope для определенной цели
     */
    public function scopeByTarget($query, $type, $id)
    {
        return $query->where('target_type', $type)->where('target_id', $id);
    }
}
