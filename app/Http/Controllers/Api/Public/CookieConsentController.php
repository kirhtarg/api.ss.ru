<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\CookieConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CookieConsentController extends Controller
{
    /**
     * Проверить статус согласия на куки
     */
    public function checkConsent(Request $request): JsonResponse
    {
        $sessionId = $request->cookie('cookie_consent_session') ?? Str::uuid()->toString();
        $ipAddress = $request->ip();

        // Проверяем согласие по session_id ИЛИ по IP-адресу
        $hasConsent = CookieConsent::hasActiveConsent($sessionId) ||
                     CookieConsent::hasActiveConsentByIp($ipAddress);

        if ($hasConsent) {
            // Сначала пытаемся найти по session_id, затем по IP
            $consent = CookieConsent::getActiveConsent($sessionId) ??
                      CookieConsent::getActiveConsentByIp($ipAddress);
            $allowedTypes = $consent->getAllowedCookieTypes();
        } else {
            $allowedTypes = ['necessary']; // По умолчанию только необходимые куки
        }

        return response()->json([
            'success' => true,
            'has_consent' => $hasConsent,
            'session_id' => $sessionId,
            'allowed_cookie_types' => $allowedTypes,
        ])->cookie('cookie_consent_session', $sessionId, 60 * 24 * 365); // Сохраняем сессию на год
    }

    /**
     * Сохранить согласие на куки
     */
    public function saveConsent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'necessary_cookies' => 'boolean',
            'analytics_cookies' => 'boolean',
            'marketing_cookies' => 'boolean',
            'preferences_cookies' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Неверные данные',
                'errors' => $validator->errors(),
            ], 422);
        }

        $sessionId = $request->cookie('cookie_consent_session') ?? Str::uuid()->toString();
        $ipAddress = $request->ip();

        // Проверяем, есть ли уже активное согласие по session_id или IP
        $existingConsent = CookieConsent::getActiveConsent($sessionId) ??
                          CookieConsent::getActiveConsentByIp($ipAddress);

        if ($existingConsent) {
            // Обновляем существующее согласие
            $consent = $existingConsent->updateConsent($request->all());
        } else {
            // Создаем новое согласие
            $consent = CookieConsent::createConsent([
                'session_id' => $sessionId,
                'ip_address' => $ipAddress,
                'user_agent' => $request->userAgent(),
                'necessary_cookies' => $request->boolean('necessary_cookies', true),
                'analytics_cookies' => $request->boolean('analytics_cookies', false),
                'marketing_cookies' => $request->boolean('marketing_cookies', false),
                'preferences_cookies' => $request->boolean('preferences_cookies', false),
            ]);
        }

        $allowedTypes = $consent->getAllowedCookieTypes();

        return response()->json([
            'success' => true,
            'message' => 'Согласие на использование куки сохранено',
            'consent_id' => $consent->id,
            'allowed_cookie_types' => $allowedTypes,
            'expires_at' => $consent->expires_at->toISOString(),
        ])->cookie('cookie_consent_session', $sessionId, 60 * 24 * 365);
    }

    /**
     * Отозвать согласие на куки
     */
    public function revokeConsent(Request $request): JsonResponse
    {
        $sessionId = $request->cookie('cookie_consent_session');

        if (! $sessionId) {
            return response()->json([
                'success' => false,
                'message' => 'Сессия не найдена',
            ], 404);
        }

        $consent = CookieConsent::getActiveConsent($sessionId);

        if (! $consent) {
            return response()->json([
                'success' => false,
                'message' => 'Активное согласие не найдено',
            ], 404);
        }

        // Помечаем согласие как истекшее
        $consent->update(['expires_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Согласие на использование куки отозвано',
        ]);
    }

    /**
     * Получить информацию о куки
     */
    public function getCookieInfo(): JsonResponse
    {
        $cookieInfo = [
            'necessary' => [
                'name' => 'Необходимые куки',
                'description' => 'Эти куки необходимы для работы сайта и не могут быть отключены. Они обычно устанавливаются в ответ на действия, которые вы выполняете, например, установка настроек конфиденциальности, вход в систему или заполнение форм.',
                'required' => true,
            ],
            'analytics' => [
                'name' => 'Аналитические куки',
                'description' => 'Эти куки позволяют нам подсчитывать посещения и источники трафика, чтобы мы могли измерять и улучшать производительность нашего сайта. Они помогают нам узнать, какие страницы наиболее и наименее популярны.',
                'required' => false,
            ],
            'marketing' => [
                'name' => 'Маркетинговые куки',
                'description' => 'Эти куки могут быть установлены через наш сайт нашими рекламными партнерами для создания профиля ваших интересов и показа вам релевантной рекламы на других сайтах.',
                'required' => false,
            ],
            'preferences' => [
                'name' => 'Куки предпочтений',
                'description' => 'Эти куки позволяют сайту запоминать сделанные вами выборы (например, ваше имя пользователя, язык или регион) и предоставлять улучшенные, более личные функции.',
                'required' => false,
            ],
        ];

        return response()->json([
            'success' => true,
            'cookie_types' => $cookieInfo,
        ]);
    }
}
