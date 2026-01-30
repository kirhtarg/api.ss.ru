<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use App\Listeners\SocialiteWasCalledListener;
use SocialiteProviders\Manager\SocialiteWasCalled;
use App\Jobs\AutoFinalizeExportsJob;

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

        // Запускаем авто-финализацию экспорта (однократный старт, далее джоба сама перепланирует себя)
        try {
            if (!app()->runningUnitTests() && !app()->environment('testing')) {
                $key = 'auto_finalize_exports_job_started';
                if (!Cache::get($key)) {
                    Cache::put($key, 1, now()->addDay());
                    AutoFinalizeExportsJob::dispatch()->delay(now()->addSeconds(10));
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
