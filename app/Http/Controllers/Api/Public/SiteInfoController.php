<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SiteInfoController extends Controller
{
    /**
     * Получить основную информацию о сайте
     */
    public function index(): JsonResponse
    {
        try {
            // Очищаем кэш для этого API
            Cache::forget('site_info_public');

            // Получаем все настройки с группой general, site, auth и shop без кэширования
            $settings = Setting::select('key', 'value', 'type', 'group')
                ->whereIn('group', ['general', 'site', 'auth', 'shop'])
                ->orWhere('key', 'site_google_font') // Включаем параметр Google Fonts из любой группы
                ->get();

            $siteInfo = [];
            foreach ($settings as $setting) {
                // Обрабатываем URL изображений для логотипов и favicon
                if (in_array($setting->key, ['site_logo', 'site_logo_negative', 'site_favicon']) && $setting->value) {
                    $siteInfo[$setting->key] = $this->getImageUrl($setting->value);
                } else {
                    $siteInfo[$setting->key] = $setting->value;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $siteInfo
            ]);

        } catch (\Exception $e) {

            // Возвращаем ошибку вместо fallback данных
            return response()->json([
                'success' => false,
                'error' => 'Ошибка получения настроек: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Получить публичные настройки сайта
     */
    public function settings(): JsonResponse
    {
        try {
            // Используем кэш для уменьшения нагрузки на базу
            $cacheKey = 'site_settings_public';
            $publicSettings = Cache::remember($cacheKey, 3600, function () {
                // Получаем только публичные настройки с group='global'
                $settings = Setting::select('key', 'value')
                    ->where('group', 'global')
                    ->whereIn('key', [
                        'admin_name',
                        'site_logo',
                        'site_description',
                        'site_keywords',
                        'contact_email',
                        'contact_phone',
                        'working_hours'
                    ])
                    ->get();

                $publicSettings = [];
                foreach ($settings as $setting) {
                    // Обрабатываем URL изображений для логотипов
                    if (in_array($setting->key, ['site_logo', 'site_logo_negative']) && $setting->value) {
                        $publicSettings[$setting->key] = $this->getImageUrl($setting->value);
                    } else {
                        $publicSettings[$setting->key] = $setting->value;
                    }
                }

                return $publicSettings;
            });

            return response()->json([
                'success' => true,
                'data' => $publicSettings
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка в SiteInfoController::settings: ' . $e->getMessage());

            // Возвращаем ошибку вместо fallback данных
            return response()->json([
                'success' => false,
                'error' => 'Ошибка получения публичных настроек: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Получить SEO настройки сайта
     */
    public function seo(): JsonResponse
    {
        try {
            // Используем кэш для уменьшения нагрузки на базу
            $cacheKey = 'site_seo_settings';
            $seoSettings = Cache::remember($cacheKey, 3600, function () {
                // Получаем SEO настройки из разных групп
                $settings = Setting::select('key', 'value', 'group')
                    ->where(function ($query) {
                        $query->where('group', 'general')
                              ->orWhere('group', 'seo')
                              ->orWhere('group', 'global');
                    })
                    ->whereIn('key', [
                        'site_name',
                        'site_description',
                        'site_logo',
                        'site_favicon',
                        'meta_title',
                        'meta_description',
                        'meta_image',
                        'meta_keywords'
                    ])
                    ->get();

                $seoSettings = [];
                foreach ($settings as $setting) {
                    // Обрабатываем URL изображений для логотипов, favicon и meta изображений
                    if (in_array($setting->key, ['site_logo', 'site_logo_negative', 'site_favicon', 'meta_image']) && $setting->value) {
                        $seoSettings[$setting->key] = $this->getImageUrl($setting->value);
                    } else {
                        $seoSettings[$setting->key] = $setting->value;
                    }
                }

                return $seoSettings;
            });

            return response()->json([
                'success' => true,
                'data' => $seoSettings
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка в SiteInfoController::seo: ' . $e->getMessage());

            // Возвращаем ошибку вместо fallback данных
            return response()->json([
                'success' => false,
                'error' => 'Ошибка получения SEO настроек: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Получить путь к изображению (относительный, без домена)
     */
    private function getImageUrl($filePath)
    {
        if (!$filePath) {
            return null;
        }

        // Если в пути есть полный URL, извлекаем только относительный путь
        if (preg_match('/https?:\/\/[^\/]+(.*)/', $filePath, $matches)) {
            $filePath = $matches[1];
        }

        // Убираем лишний слэш в начале
        $filePath = ltrim($filePath, '/');

        // Если путь начинается с images/, возвращаем с ведущим слэшем
        if (str_starts_with($filePath, 'images/')) {
            return '/' . $filePath;
        }

        // Возвращаем относительный путь к файлу в папке public/images/
        return '/images/' . $filePath;
    }
}
