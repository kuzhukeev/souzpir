<?php

namespace Tests\Feature\Vision\Settings;

use App\Vision\Settings\SchedulePreview;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SlotEstimateE815ITest extends TestCase
{
    public function test_counts_saved_automatic_runs_at_calendar_boundaries(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 00:00:00+00');
        $estimate = new SchedulePreview;
        $schedule = ['enabled' => true, 'next_run_at' => '2026-11-02 06:00:00+00', 'timezone' => 'Europe/Moscow', 'frequency' => 'weekly', 'weekday' => 1, 'local_time' => '09:00', 'models' => ['a', 'b']];
        self::assertSame(['runs' => 5, 'slots' => 30], $estimate->estimate($schedule, 3, '2026-11-01', '2026-11-30'));
        self::assertSame(['runs' => 1, 'slots' => 6], $estimate->estimate($schedule, 3, '2026-11-02', '2026-11-02'));
        self::assertSame(['runs' => 0, 'slots' => 0], $estimate->estimate($schedule, 3, '2026-10-01', '2026-10-31'));
        $schedule['frequency'] = 'biweekly';
        self::assertSame(['runs' => 3, 'slots' => 18], $estimate->estimate($schedule, 3, '2026-11-01', '2026-11-30'));
        $schedule['frequency'] = 'monthly';
        self::assertSame(['runs' => 2, 'slots' => 12], $estimate->estimate($schedule, 3, '2026-11-02', '2026-12-07'));
        self::assertNull($estimate->estimate($schedule, null, '2026-11-01', '2026-11-30'));
        $schedule['enabled'] = false;
        self::assertSame(['runs' => 0, 'slots' => 0], $estimate->estimate($schedule, null, '2026-11-01', '2026-11-30'));
        CarbonImmutable::setTestNow();
    }
}
