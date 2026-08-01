<?php

namespace Tests\Feature\PartnerApi;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PartnerReservationScheduleTest extends TestCase
{
    public function test_expired_reservation_cleanup_is_scheduled_every_five_minutes_without_overlap(): void
    {
        Artisan::call('schedule:list');
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'partner-api-release-expired-stock-reservations');

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(
            'framework/schedule-'.sha1('partner-api-release-expired-stock-reservations'),
            $event->mutexName(),
        );
    }

    public function test_expired_quote_cleanup_is_hourly_and_cannot_overlap(): void
    {
        Artisan::call('schedule:list');
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'partner-api-delete-expired-checkout-quotes');

        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
