<?php

if (!function_exists('get_setting')) {
    /**
     * Получение значения настройки по ключу
     * 
     * @param string $key Ключ настройки
     * @param mixed $default Значение по умолчанию
     * @param string $group Группа настроек (по умолчанию 'general')
     * @return mixed
     */
    function get_setting($key, $default = null, $group = 'general')
    {
        try {
            $value = \Illuminate\Support\Facades\DB::table('settings')
                ->where('key', $key)
                ->where('group', $group)
                ->value('value');
            
            return $value !== null ? $value : $default;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Ошибка получения настройки {$key}: " . $e->getMessage());
            return $default;
        }
    }
}

if (!function_exists('get_shop_setting')) {
    /**
     * Получение значения настройки магазина по ключу
     * 
     * @param string $key Ключ настройки
     * @param mixed $default Значение по умолчанию
     * @return mixed
     */
    function get_shop_setting($key, $default = null)
    {
        return get_setting($key, $default, 'shop');
    }
}
