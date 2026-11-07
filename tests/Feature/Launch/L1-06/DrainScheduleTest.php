<?php

namespace Tests\Feature\Launch\L1_06;

use App\Seller\Notifications\DrainNotificationsSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class DrainScheduleTest extends TestCase
{
    public function test_schedule_registers_notifications_drain_every_minute_without_overlapping(): void
    {
        $schedule = app(Schedule::class);
        (new DrainNotificationsSchedule)->register($schedule);
        $event = collect($schedule->events())->first(fn ($event) => str_contains($event->command, 'notifications:drain'));
        self::assertNotNull($event);
        self::assertSame('* * * * *', $event->expression);
        self::assertTrue($event->withoutOverlapping);
    }
}
