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

if (!function_exists('frontend_public_path')) {
    /**
     * Абсолютный путь к папке public фронтенд-приложения.
     * Берётся из FRONTEND_PATH в .env (без запасных значений в коде).
     *
     * @param string $subpath Подпуть относительно public (например 'images/settings')
     * @return string
     * @throws \RuntimeException если FRONTEND_PATH не задан в .env
     */
    function frontend_public_path(string $subpath = ''): string
    {
        $path = config('frontend.path');
        if (empty($path)) {
            throw new \RuntimeException(
                'FRONTEND_PATH должен быть задан в .env (относительный путь к папке фронтенда, напр. ../admin.skateandsnow.ru)'
            );
        }
        $base = base_path(rtrim($path, '/') . '/public');
        return $subpath !== '' ? rtrim($base, '/') . '/' . ltrim($subpath, '/') : $base;
    }
}

if (!function_exists('mb_ucfirst')) {
    /**
     * Преобразует первый символ строки в верхний регистр (с поддержкой UTF-8)
     * 
     * @param string $string Строка для преобразования
     * @param string $encoding Кодировка (по умолчанию UTF-8)
     * @return string
     */
    function mb_ucfirst($string, $encoding = 'UTF-8')
    {
        $firstChar = mb_substr($string, 0, 1, $encoding);
        $rest = mb_substr($string, 1, null, $encoding);
        return mb_strtoupper($firstChar, $encoding) . $rest;
    }
}