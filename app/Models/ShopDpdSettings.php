<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopDpdSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_number',
        'api_key',
        'sender_company',
        'sender_name',
        'sender_phone',
        'sender_email',
        'sender_inn',
        'sender_kpp',
        'sender_city',
        'sender_street',
        'sender_house',
        'sender_flat',
        'sender_postal_code',
        'default_weight',
        'default_length',
        'default_width',
        'default_height',
        'is_active'
    ];

    protected $casts = [
        'default_weight' => 'decimal:2',
        'default_length' => 'decimal:2',
        'default_width' => 'decimal:2',
        'default_height' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    /**
     * Получить активные настройки DPD
     */
    public static function getActive()
    {
        return self::where('is_active', true)->first();
    }
}


















