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
                // Обрабатываем URL изображений для логотипов
                if (in_array($setting->key, ['site_logo', 'site_logo_negative']) && $setting->value) {
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
            Log::error('Ошибка в SiteInfoController::index: ' . $e->getMessage());

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
                        'meta_title',
                        'meta_description',
                        'meta_image',
                        'meta_keywords'
                    ])
                    ->get();

                $seoSettings = [];
                foreach ($settings as $setting) {
                    // Обрабатываем URL изображений для логотипов и meta изображений
                    if (in_array($setting->key, ['site_logo', 'site_logo_negative', 'meta_image']) && $setting->value) {
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
     * Получить полный URL изображения
     */
    private function getImageUrl($filePath)
    {
        if (!$filePath) {
            return null;
        }

        // Если это уже полный URL, проверяем домен
        if (str_starts_with($filePath, 'http')) {
            // Заменяем старый домен на новый фронтенд домен
            $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
            $oldDomains = [
                'https://ss75.kirhtarg.ru',
                'https://api.ss.ru',
                'https://ss75-api.kirhtarg.ru'
            ];
            
            foreach ($oldDomains as $oldDomain) {
                if (str_starts_with($filePath, $oldDomain)) {
                    return str_replace($oldDomain, $frontendUrl, $filePath);
                }
            }
            
            // Если это другой домен, возвращаем как есть
            return $filePath;
        }

        // Убираем лишний префикс images/ если он уже есть
        $cleanPath = ltrim($filePath, '/');
        if (str_starts_with($cleanPath, 'images/')) {
            // Возвращаем полный URL с фронтенда
            $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
            return $frontendUrl . '/' . $cleanPath;
        }

        // Возвращаем полный URL к файлу в папке public/images/ на фронтенде
        $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
        return $frontendUrl . '/images/' . $cleanPath;
    }
}
