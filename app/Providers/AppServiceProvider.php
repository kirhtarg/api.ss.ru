<?php

namespace App\Providers;

use App\Listeners\SocialiteWasCalledListener;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
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

        // HTTP-процесс не может надёжно найти Supervisor worker через ps/exec.
        // Worker сам подтверждает активность через общий cache store.
        $writeQueueHeartbeat = static function (): void {
            static $lastHeartbeatAt = 0;

            if (time() - $lastHeartbeatAt < 15) {
                return;
            }

            $lastHeartbeatAt = time();
            try {
                Cache::put('queue_worker_heartbeat', [
                    'timestamp' => $lastHeartbeatAt,
                    'connection' => config('queue.default'),
                    'hostname' => gethostname() ?: null,
                    'pid' => getmypid(),
                ], now()->addMinutes(3));
            } catch (\Throwable $e) {
                // Ошибка диагностики не должна останавливать обработку очереди.
            }
        };

        Queue::looping($writeQueueHeartbeat);
        Queue::before($writeQueueHeartbeat);

    }
}
