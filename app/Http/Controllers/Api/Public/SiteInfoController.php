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
            // Используем кэш для уменьшения нагрузки на базу
            $cacheKey = 'site_info_public';
            $siteInfo = Cache::remember($cacheKey, 3600, function () {
                // Получаем только необходимые настройки
                $settings = Setting::select('key', 'value')
                    ->whereIn('key', [
                        'admin_name',
                        'site_logo',
                        'main_site',
                        'site_description',
                        'site_keywords'
                    ])
                    ->get();

                $siteInfo = [];
                foreach ($settings as $setting) {
                    $siteInfo[$setting->key] = $setting->value;
                }

                return $siteInfo;
            });

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
                    $publicSettings[$setting->key] = $setting->value;
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
}
