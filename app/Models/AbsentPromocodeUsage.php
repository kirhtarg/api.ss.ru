<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbsentPromocodeUsage extends Model
{
    use HasFactory;

    protected $table = 'absent_promocode_usages';

    protected $fillable = [
        'user_id',
        'good_id',
        'promocode_id',
    ];

    /**
     * Связь с пользователем
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Связь с товаром
     */
    public function good(): BelongsTo
    {
        return $this->belongsTo(ShopGood::class, 'good_id');
    }

    /**
     * Связь с промокодом
     */
    public function promocode(): BelongsTo
    {
        return $this->belongsTo(Promocode::class);
    }
}
