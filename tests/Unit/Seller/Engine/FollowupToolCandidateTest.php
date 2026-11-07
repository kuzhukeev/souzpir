<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\ToolRunner;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class FollowupToolCandidateTest extends TestCase
{
    public function test_followup_returns_only_a_candidate_using_supplied_clock(): void
    {
        $clock = CarbonImmutable::parse('2026-11-01 12:00', 'Europe/Moscow');
        $result = (new ToolRunner)->run('set_followup', ['datetime' => 'завтра'], ['now' => $clock]);
        self::assertSame('candidate', $result['status']);
        self::assertSame('2026-11-02T11:00:00+03:00', $result['datetime']);
        self::assertTrue($result['time_defaulted']);
        self::assertFalse($result['day_defaulted']);
        self::assertFalse($result['year_defaulted']);
        self::assertSame('2026-11-01T12:00:00+03:00', $clock->toIso8601String());
    }

    public function test_missing_clock_invalid_arguments_and_ambiguous_date_are_not_executed(): void
    {
        $runner = new ToolRunner;
        $clock = CarbonImmutable::parse('2026-11-01 12:00', 'Europe/Moscow');
        self::assertSame(['status' => 'unavailable'], $runner->run('set_followup', ['datetime' => 'завтра'], []));
        self::assertSame(['status' => 'invalid_arguments'], $runner->run('set_followup', [], ['now' => $clock]));
        self::assertSame(['status' => 'invalid_arguments'], $runner->run('set_followup', ['datetime' => 5], ['now' => $clock]));
        self::assertSame(['status' => 'needs_clarification'], $runner->run('set_followup', ['datetime' => 'когда-нибудь'], ['now' => $clock]));
    }
}
