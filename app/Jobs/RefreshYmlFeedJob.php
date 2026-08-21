<?php

namespace App\Jobs;

use App\Services\YmlFeedService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshYmlFeedJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 120;
    public int $timeout = 600;

    public function handle(YmlFeedService $service): void
    {
        $result = $service->generate();
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException($result['message'] ?? 'Не удалось обновить YML-фид.');
        }
    }
}
