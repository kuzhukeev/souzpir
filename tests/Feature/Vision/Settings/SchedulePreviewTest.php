<?php

namespace Tests\Feature\Vision\Settings;

use App\Vision\Settings\SchedulePreview;
use PHPUnit\Framework\TestCase;

final class SchedulePreviewTest extends TestCase
{
    public function test_three_weekly_runs_use_brand_timezone(): void
    {
        $schedule = ['enabled' => true, 'next_run_at' => '2026-11-02 06:00:00+00',
            'timezone' => 'Europe/Moscow', 'frequency' => 'weekly', 'weekday' => 1, 'local_time' => '09:00'];

        self::assertSame(['2026-11-02 09:00', '2026-11-09 09:00', '2026-11-16 09:00'],
            (new SchedulePreview)->nextThree($schedule));
    }

    public function test_monthly_runs_choose_first_selected_weekday(): void
    {
        $schedule = ['enabled' => true, 'next_run_at' => '2026-11-02 06:00:00+00',
            'timezone' => 'Europe/Moscow', 'frequency' => 'monthly', 'weekday' => 1, 'local_time' => '09:00'];

        self::assertSame(['2026-11-02 09:00', '2026-12-07 09:00', '2027-01-04 09:00'],
            (new SchedulePreview)->nextThree($schedule));
    }

    public function test_disabled_schedule_has_no_automatic_runs(): void
    {
        self::assertSame([], (new SchedulePreview)->nextThree(['enabled' => false, 'next_run_at' => null]));
    }
}
