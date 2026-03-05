<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopUserAddress extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'city',
        'postal_code',
        'street',
        'house',
        'apartment',
        'entrance',
        'intercom',
        'comment',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Связь с пользователем
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить полный адрес
     */
    public function getFullAddressAttribute(): string
    {
        $address = $this->city.', '.$this->street.', д. '.$this->house;

        if ($this->apartment) {
            $address .= ', кв. '.$this->apartment;
        }

        if ($this->entrance) {
            $address .= ', подъезд '.$this->entrance;
        }

        return $address;
    }

    /**
     * Получить краткий адрес
     */
    public function getShortAddressAttribute(): string
    {
        return $this->street.', д. '.$this->house.($this->apartment ? ', кв. '.$this->apartment : '');
    }
}
