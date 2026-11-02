<?php

namespace Tests\Unit\Seller\Settings\Schedule;

use App\Seller\Settings\Schedule\AssignmentSelector;
use App\Seller\Settings\Schedule\Availability;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SchedulePolicyTest extends TestCase
{
    public function test_default_and_working_window_with_timezone_and_holiday(): void
    {
        $policy = new Availability;
        $monday = CarbonImmutable::parse('2026-11-02 07:00:00+00');
        self::assertSame(['mode' => 'autonomous', 'notify_managers' => true], $policy->forTime([], $monday, 'Europe/Moscow'));
        $week = array_fill(0, 7, ['enabled' => true, 'start' => '09:00', 'end' => '18:00', 'behavior' => 'quiet']);
        self::assertSame(['mode' => 'autonomous', 'notify_managers' => false], $policy->forTime(['week' => $week], $monday, 'Europe/Moscow'));
        self::assertSame('off', $policy->forTime(['week' => $week], $monday->setTime(16, 0), 'Europe/Moscow')['mode']);
        self::assertSame('off', $policy->forTime(['week' => $week, 'holidays' => ['2026-11-02']], $monday, 'Europe/Moscow')['mode']);
        self::assertSame('suggest', $policy->forTime([], $monday, 'Europe/Moscow', 'suggest')['mode']);
    }

    public function test_round_robin_and_fixed_choice(): void
    {
        $selector = new AssignmentSelector;
        self::assertSame('a', $selector->select('round_robin', ['b', 'a']));
        self::assertSame('b', $selector->select('round_robin', ['b', 'a'], 'a'));
        self::assertSame('a', $selector->select('round_robin', ['a', 'b'], 'b'));
        self::assertSame('b', $selector->select('fixed', ['a', 'b'], null, 'b'));
        self::assertNull($selector->select('fixed', ['a'], null, 'b'));
        self::assertNull($selector->select('first_taken', ['a']));
    }
}
