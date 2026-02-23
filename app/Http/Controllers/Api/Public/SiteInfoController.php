<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\ShopCdekSettings;
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
                ->where(function ($query) {
                    $query->whereIn('group', ['general', 'site', 'auth', 'shop'])
                          ->orWhere('key', 'site_google_font') // Включаем параметр Google Fonts из любой группы
                          ->orWhere('key', 'yandex_metrika') // Включаем параметр Яндекс.Метрики из любой группы
                          ->orWhere('key', 'jivo_script') // Включаем параметр скрипта Jivo из любой группы
                          ->orWhere('key', 'site_favicon') // Включаем параметр favicon из любой группы
                          ->orWhere('key', 'show_counters') // Включаем параметр show_counters из любой группы
                          ->orWhere('key', 'absent_promocode_percent') // Включаем параметр процента промокода за отсутствие товара
                          ->orWhere('key', 'absent_promocode_percent_days') // Включаем параметр дней действия промокода
                          ->orWhere('key', 'suff_support') // Включаем параметр суффиксов для совместимости со старым сайтом
                          ->orWhere('key', 'over_tax') // Включаем параметр процента наценки
                          ->orWhere('key', 'over_text'); // Включаем параметр текста наценки
                })
                ->get();

            $siteInfo = [];
            foreach ($settings as $setting) {
                if ($setting->key === 'jivo_script' && $setting->value) {
                    $adjustScript = '<script>(function(){function a(){if(window.innerWidth>1024)return;var b=document.querySelector(".button__QIiE1");if(!b)return;b.style.setProperty("bottom","80px","important");b.style.setProperty("margin-bottom","0px","important")}var c=0,d=60,e=setInterval(function(){a();c++;if(document.querySelector(".button__QIiE1")||c>=d)clearInterval(e)},500);window.addEventListener("resize",a)})();</script>';
                    $siteInfo[$setting->key] = $adjustScript . $setting->value;
                } elseif (in_array($setting->key, ['site_logo', 'site_logo_negative', 'site_favicon']) && $setting->value) {
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
            Log::error('Ошибка в SiteInfoController::index: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

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
            // Очищаем кэш для этого API (как в index())
            Cache::forget('site_settings_public');
            
            // Используем кэш для уменьшения нагрузки на базу
            $cacheKey = 'site_settings_public';
            $publicSettings = Cache::remember($cacheKey, 3600, function () {
                // Получаем только публичные настройки с group='global'
                $settings = Setting::select('key', 'value')
                    ->where(function ($query) {
                        $query->where('group', 'global')
                              ->whereIn('key', [
                                  'admin_name',
                                  'site_logo',
                                  'site_description',
                                  'site_keywords',
                                  'contact_email',
                                  'contact_phone',
                                  'working_hours'
                              ]);
                    })
                    ->orWhere('key', 'show_counters') // Включаем параметр show_counters из любой группы
                    ->orWhere('key', 'tag_max_bonus') // Включаем параметр повышенного бонуса
                    ->orWhere('key', 'tag_max_bonus_tax') // Включаем процент повышенного бонуса
                    ->orWhere('key', 'tag_no_bonus') // Включаем параметр тега без бонусов
                    ->orWhere('key', 'delivery_only_info') // Включаем параметр режима доставки только для информации
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

            // Добавляем публичную часть настроек СДЭК
            $cdek = ShopCdekSettings::getActive();
            if ($cdek) {
                $publicSettings['cdek_settings'] = [
                    'default_weight' => $cdek->default_weight !== null ? (float) $cdek->default_weight : null,
                    'default_length' => $cdek->default_length !== null ? (float) $cdek->default_length : null,
                    'default_width' => $cdek->default_width !== null ? (float) $cdek->default_width : null,
                    'default_height' => $cdek->default_height !== null ? (float) $cdek->default_height : null,
                    'cash_on_delivery_enabled' => (bool) $cdek->cash_on_delivery_enabled,
                    'customer_pays_delivery' => (bool) $cdek->customer_pays_delivery,
                    'disable_order_creation' => (bool) $cdek->disable_order_creation,
                    'always_enable_insurance' => (bool) $cdek->always_enable_insurance,
                    'sender_city' => $cdek->sender_city,
                ];
            }

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
        if (!$filePath || !is_string($filePath)) {
            return null;
        }

        try {
            // Если в пути есть полный URL, извлекаем только относительный путь
            if (preg_match('/https?:\/\/[^\/]+(.*)/', $filePath, $matches)) {
                $filePath = $matches[1];
            }

            // Убираем лишний слэш в начале
            $filePath = ltrim($filePath, '/');

            // Если путь пустой после обработки
            if (empty($filePath)) {
                return null;
            }

            // Если путь начинается с images/, возвращаем с ведущим слэшем
            if (str_starts_with($filePath, 'images/')) {
                return '/' . $filePath;
            }

            // Возвращаем относительный путь к файлу в папке public/images/
            return '/images/' . $filePath;
        } catch (\Exception $e) {
            Log::error('Ошибка в getImageUrl: ' . $e->getMessage(), ['filePath' => $filePath]);
            return null;
        }
    }
}
