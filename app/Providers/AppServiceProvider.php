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
        // MAIL_FROM_ADDRESS берется только из .env файла
        // Никакой автоматической генерации!

        // Регистрируем VK провайдер для Socialite
        Event::listen(SocialiteWasCalled::class, SocialiteWasCalledListener::class);
    }
}
