<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopCdekSettings extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'client_secret',
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
        'sender_country_code',
        'sender_city_code',
        'developer_key',
        'default_weight',
        'default_length',
        'default_width',
        'default_height',
        'tariffs',
        'cash_on_delivery_enabled',
        'is_active'
    ];

    protected $casts = [
        'tariffs' => 'array',
        'default_weight' => 'decimal:2',
        'default_length' => 'decimal:2',
        'default_width' => 'decimal:2',
        'default_height' => 'decimal:2',
        'cash_on_delivery_enabled' => 'boolean',
        'is_active' => 'boolean'
    ];

    /**
     * Получить активные настройки СДЭК
     */
    public static function getActive()
    {
        return self::where('is_active', true)->first();
    }

    /**
     * Получить тарифы как массив
     */
    public function getTariffsAttribute($value)
    {
        return json_decode($value, true) ?? [];
    }

    /**
     * Установить тарифы
     */
    public function setTariffsAttribute($value)
    {
        $this->attributes['tariffs'] = json_encode($value);
    }

    /**
     * Получить коды активных тарифов
     */
    public function getActiveTariffCodes()
    {
        $tariffs = $this->tariffs ?? [];
        return array_column(array_filter($tariffs, function($tariff) {
            return isset($tariff['enabled']) && $tariff['enabled'];
        }), 'tariff_code');
    }
}
