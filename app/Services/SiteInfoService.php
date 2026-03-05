<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SiteInfoService
{
    /**
     * Получить информацию о сайте для писем
     */
    public static function getSiteInfoForEmail(): array
    {
        $cacheKey = 'site_info_for_email';

        return Cache::remember($cacheKey, 3600, function () {
            $settings = Setting::select('key', 'value')
                ->whereIn('key', [
                    'site_name',
                    'site_description',
                    'main_site',
                    'site_logo',
                    'site_color1',
                    'site_color2',
                ])
                ->where('group', '<>', 'private')
                ->get();

            $siteInfo = [];
            foreach ($settings as $setting) {
                $siteInfo[$setting->key] = $setting->value;
            }

            // Добавляем значения по умолчанию если не найдены
            $siteInfo['site_name'] = $siteInfo['site_name'] ?? config('app.name');
            $siteInfo['site_description'] = $siteInfo['site_description'] ?? 'Добро пожаловать на наш сайт!';
            $siteInfo['main_site'] = $siteInfo['main_site'] ?? config('app.frontend_url', env('FRONTEND_URL', config('app.url')));
            $siteInfo['site_logo'] = $siteInfo['site_logo'] ?? null;
            $siteInfo['site_color1'] = $siteInfo['site_color1'] ?? '#1a1a1a';
            $siteInfo['site_color2'] = $siteInfo['site_color2'] ?? '#b8860b';

            return $siteInfo;
        });
    }
}
