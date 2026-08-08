<?php

namespace Tests\Feature\Seo;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SitemapScheduleTest extends TestCase
{
    public function test_sitemap_generation_is_scheduled_daily_without_overlap(): void
    {
        Artisan::call('schedule:list');

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'seo-generate-sitemap');

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertSame('15 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
