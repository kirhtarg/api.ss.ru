<?php

namespace App\Jobs;

use App\Services\YmlFeedService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RefreshYmlFeedJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $uniqueFor = 120;
    public int $timeout = 600;

    public function middleware(): array
    {
        // A long import may enqueue a follow-up refresh after the first job
        // starts. Never build two full YML files concurrently.
        return [(new WithoutOverlapping('refresh-yml-feed'))->releaseAfter(10)->expireAfter(660)];
    }

    public function handle(YmlFeedService $service): void
    {
        $result = $service->generate();
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException($result['message'] ?? 'Не удалось обновить YML-фид.');
        }
    }
}
