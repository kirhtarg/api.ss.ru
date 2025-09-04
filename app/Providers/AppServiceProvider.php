<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
            $domain = parse_url($frontendUrl, PHP_URL_HOST);
            if ($domain && $domain !== 'localhost') {
                config(['mail.from.address' => 'noreply@' . $domain]);
            } else {
                // Для localhost используем безопасный адрес
                config(['mail.from.address' => 'noreply@example.com']);
            }
        }
    }
}
