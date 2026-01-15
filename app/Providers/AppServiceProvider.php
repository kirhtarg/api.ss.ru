<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Listeners\SocialiteWasCalledListener;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Автоматически генерируем MAIL_FROM_ADDRESS если не задан явно
        if (!env('MAIL_FROM_ADDRESS')) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

            // Извлекаем домен из URL
            $domain = null;
            if (filter_var($frontendUrl, FILTER_VALIDATE_URL)) {
                // Если это полный URL с протоколом
                $domain = parse_url($frontendUrl, PHP_URL_HOST);
            } elseif (preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $frontendUrl)) {
                // Если это просто домен без протокола
                $domain = $frontendUrl;
            }

            // Определяем адрес отправителя
            if ($domain && !in_array($domain, ['localhost', '127.0.0.1', '::1'])) {
                // Используем info@ для доменов skateandsnow.ru
                if (str_contains($domain, 'skateandsnow.ru')) {
                    config(['mail.from.address' => 'info@skateandsnow.ru']);
                } else {
                    config(['mail.from.address' => 'noreply@' . $domain]);
                }
            } else {
                // Для localhost используем info@skateandsnow.ru как fallback
                config(['mail.from.address' => 'info@skateandsnow.ru']);
            }
        }

        // Регистрируем VK провайдер для Socialite
        Event::listen(SocialiteWasCalled::class, SocialiteWasCalledListener::class);
    }
}
