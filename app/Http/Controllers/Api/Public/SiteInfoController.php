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
                    Log::info('SiteInfoController::index: Processing ' . $setting->key . ' = ' . $setting->value);
                    $siteInfo[$setting->key] = $this->getImageUrl($setting->value);
                    Log::info('SiteInfoController::index: Processed ' . $setting->key . ' = ' . $siteInfo[$setting->key]);
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
                        Log::info('SiteInfoController::settings: Processing ' . $setting->key . ' = ' . $setting->value);
                        $publicSettings[$setting->key] = $this->getImageUrl($setting->value);
                        Log::info('SiteInfoController::settings: Processed ' . $setting->key . ' = ' . $publicSettings[$setting->key]);
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
                        Log::info('SiteInfoController::seo: Processing ' . $setting->key . ' = ' . $setting->value);
                        $seoSettings[$setting->key] = $this->getImageUrl($setting->value);
                        Log::info('SiteInfoController::seo: Processed ' . $setting->key . ' = ' . $seoSettings[$setting->key]);
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
            Log::info('getImageUrl: filePath is null or empty');
            return null;
        }

        Log::info('getImageUrl: Original filePath = ' . $filePath);
        Log::info('getImageUrl: config app.url = ' . config('app.url'));
        Log::info('getImageUrl: config app.frontend_url = ' . config('app.frontend_url'));

        // Убираем возможные префиксы API сервера
        $cleanPath = $filePath;
        
        // Если в пути есть полный URL, извлекаем только относительный путь
        if (preg_match('/https?:\/\/[^\/]+(.*)/', $filePath, $matches)) {
            $cleanPath = $matches[1];
            Log::info('getImageUrl: Extracted relative path from full URL = ' . $cleanPath);
        }
        
        // Если это уже полный URL, проверяем домен
        if (str_starts_with($cleanPath, 'http')) {
            Log::info('getImageUrl: cleanPath is full URL = ' . $cleanPath);
            
            // Заменяем старый домен на новый фронтенд домен
            $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
            $oldDomains = [
                'https://ss75.kirhtarg.ru',
                'https://api.ss.ru',
                'https://ss75-api.kirhtarg.ru'
            ];
            
            foreach ($oldDomains as $oldDomain) {
                if (str_starts_with($cleanPath, $oldDomain)) {
                    $result = str_replace($oldDomain, $frontendUrl, $cleanPath);
                    Log::info('getImageUrl: Replaced domain ' . $oldDomain . ' with ' . $frontendUrl . ' = ' . $result);
                    return $result;
                }
            }
            
            // Если это другой домен, возвращаем как есть
            Log::info('getImageUrl: Unknown domain, returning as is = ' . $cleanPath);
            return $cleanPath;
        }

        // Убираем лишний префикс images/ если он уже есть
        $cleanPath = ltrim($cleanPath, '/');
        Log::info('getImageUrl: Cleaned path = ' . $cleanPath);
        
        if (str_starts_with($cleanPath, 'images/')) {
            // Возвращаем полный URL с фронтенда
            $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
            $result = $frontendUrl . '/' . $cleanPath;
            Log::info('getImageUrl: Final result for images/ path = ' . $result);
            return $result;
        }

        // Возвращаем полный URL к файлу в папке public/images/ на фронтенде
        $frontendUrl = config('app.frontend_url', 'https://admin.skateandsnow.ru');
        $result = $frontendUrl . '/images/' . $cleanPath;
        Log::info('getImageUrl: Final result for regular path = ' . $result);
        return $result;
    }
}
