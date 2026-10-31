<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\FollowupDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FollowupDateTest extends TestCase
{
    public function test_prototype_fixture_preserves_observed_ambiguities_as_reference_not_as_execution_dates(): void
    {
        $fixture = $this->fixture();
        self::assertSame('e29606398a1da572d4e6bbb6cf27c7a9ae755a618f4da7219dfa151e73909e64', $fixture['source_sha256']);
        self::assertSame(2, $fixture['default_days']);
        self::assertCount(24, $fixture['cases']);
        self::assertSame('2027-10-05 11:00:00 +03:00', $fixture['cases'][19]['prototype']);
        self::assertSame('2026-03-03 11:00:00 +03:00', $fixture['cases'][9]['prototype']);
        self::assertSame('2026-11-02 11:00:00 +03:00', $fixture['cases'][10]['prototype']);
        self::assertSame('2026-10-31 11:00:00 +03:00', $fixture['cases'][22]['prototype']);
    }

    public function test_unambiguous_future_cases_match_prototype_with_defaults_marked(): void
    {
        $fixture = $this->fixture();
        $clarifications = [6, 9, 10, 17, 18, 19, 20, 22];
        $previousZone = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            foreach ($fixture['cases'] as $index => $case) {
                $now = CarbonImmutable::parse($case['now'], $case['timezone']);
                $actual = (new FollowupDate)->parse($case['input'], $now);
                if (in_array($index, $clarifications, true)) {
                    self::assertSame('needs_clarification', $actual['status'], $case['input']);
                    self::assertNull($actual['date'], $case['input']);
                    self::assertNotNull($actual['reason']);
                } else {
                    self::assertSame('candidate', $actual['status'], $case['input']);
                    self::assertSame($case['prototype'], $actual['date']->format('Y-m-d H:i:s P'), $case['input']);
                    self::assertSame(! in_array($index, [11, 12], true), $actual['time_defaulted'], $case['input']);
                    self::assertSame(in_array($index, [7, 8, 21], true), $actual['day_defaulted'], $case['input']);
                    self::assertSame(in_array($index, [7, 8, 21], true), $actual['year_defaulted'], $case['input']);
                    self::assertNull($actual['reason']);
                }
                self::assertSame($case['now'], $now->format('Y-m-d H:i:s'));
                self::assertSame('UTC', date_default_timezone_get());
            }
        } finally {
            date_default_timezone_set($previousZone);
        }
    }

    public function test_invalid_unknown_ambiguous_or_non_future_values_require_clarification(): void
    {
        $now = CarbonImmutable::parse('2026-10-31 23:00:00', 'Europe/Moscow');
        foreach (['', 'неизвестно', 'не завтра', 'завтра или в пятницу', 'конец октября', 'в конце октября',
            '31.02', '2026-02-30', '2027-02-29', '2026-11-05 24:00', '2026-11-05 12:60',
            '2026-11-05 12:00+25:00', '2026-11-05 12:00-00:00', '2026-10-31 22:59', 'через 0 дней', '25 октября'] as $input) {
            $actual = (new FollowupDate)->parse($input, $now);
            self::assertSame('needs_clarification', $actual['status'], $input);
            self::assertNull($actual['date'], $input);
            self::assertNotNull($actual['reason'], $input);
        }
    }

    public function test_dst_gap_and_repeated_local_times_require_clarification_but_explicit_offsets_identify_instants(): void
    {
        $parser = new FollowupDate;
        $spring = CarbonImmutable::parse('2026-03-01 12:00', 'Europe/Berlin');
        $fall = CarbonImmutable::parse('2026-10-01 12:00', 'Europe/Berlin');
        foreach ([[$spring, '2026-03-29 02:30'], [$fall, '2026-10-25 02:30']] as [$now, $input]) {
            $actual = $parser->parse($input, $now);
            self::assertSame('needs_clarification', $actual['status']);
            self::assertSame('ambiguous_local_time', $actual['reason']);
            self::assertNull($actual['date']);
        }
        $early = $parser->parse('2026-10-25T02:30:00+02:00', $fall);
        $late = $parser->parse('2026-10-25T02:30:00+01:00', $fall);
        self::assertSame('candidate', $early['status']);
        self::assertSame('candidate', $late['status']);
        self::assertSame(3600, $late['date']->timestamp - $early['date']->timestamp);
        self::assertFalse($early['time_defaulted']);
        self::assertFalse($late['time_defaulted']);
        $precise = $parser->parse('2026-10-25T02:30:00.123456+02:00', $fall);
        self::assertSame('candidate', $precise['status']);
        self::assertSame('123456', $precise['date']->format('u'));
    }

    public function test_explicit_date_without_time_and_relative_dates_mark_prototype_defaults(): void
    {
        $now = CarbonImmutable::parse('2026-10-31 10:00', 'Europe/Moscow');
        foreach (['2026-11-05', 'завтра', 'на завтра', 'послезавтра', 'на послезавтра', 'в пятницу', 'через неделю'] as $input) {
            $actual = (new FollowupDate)->parse($input, $now);
            self::assertSame('candidate', $actual['status'], $input);
            self::assertTrue($actual['time_defaulted'], $input);
        }
        $numeric = (new FollowupDate)->parse('12.11', $now);
        self::assertSame('candidate', $numeric['status']);
        self::assertSame('2026-11-12 11:00:00 +03:00', $numeric['date']->format('Y-m-d H:i:s P'));
        self::assertTrue($numeric['year_defaulted']);
        self::assertFalse($numeric['day_defaulted']);
    }

    public function test_month_forms_with_preposition_and_bare_month_preserve_prototype_defaults(): void
    {
        $now = CarbonImmutable::parse('2026-02-01 10:00:00', 'Europe/Moscow');
        foreach ([
            'в октябре' => '2026-10-05 11:00:00 +03:00',
            'октябрь' => '2026-10-05 11:00:00 +03:00',
            'в марте' => '2026-03-05 11:00:00 +03:00',
            'март' => '2026-03-05 11:00:00 +03:00',
        ] as $input => $expected) {
            $actual = (new FollowupDate)->parse($input, $now);
            self::assertSame('candidate', $actual['status'], $input);
            self::assertSame($expected, $actual['date']->format('Y-m-d H:i:s P'), $input);
            self::assertSame('month', $actual['source'], $input);
            self::assertTrue($actual['time_defaulted'], $input);
            self::assertTrue($actual['day_defaulted'], $input);
            self::assertTrue($actual['year_defaulted'], $input);
        }
        $ambiguous = (new FollowupDate)->parse('в конце октября', $now);
        self::assertSame('needs_clarification', $ambiguous['status']);
        self::assertNull($ambiguous['date']);
    }

    public function test_an_entire_skipped_civil_day_never_moves_the_candidate_to_another_day(): void
    {
        $parser = new FollowupDate;
        foreach ([
            ['2011-12-29 10:00', 'завтра'],
            ['2011-12-01 10:00', '30.12'],
            ['2011-12-01 10:00', '2011-12-30 11:00'],
        ] as [$clock, $input]) {
            $actual = $parser->parse($input, CarbonImmutable::parse($clock, 'Pacific/Apia'));
            self::assertSame('needs_clarification', $actual['status'], $input);
            self::assertSame('ambiguous_local_time', $actual['reason'], $input);
            self::assertNull($actual['date'], $input);
        }
    }

    public function test_invalid_utf8_extreme_day_count_and_pattern_failure_are_sanitized(): void
    {
        $now = CarbonImmutable::parse('2026-10-31', 'Europe/Moscow');
        foreach (["\xff PRIVATE-SENTINEL", 'через '.PHP_INT_MAX.' дней', 'через 106751991167300 дней', str_repeat('x', 16385).'PRIVATE-SENTINEL'] as $input) {
            try {
                (new FollowupDate)->parse($input, $now);
                self::fail('Unsafe input accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Follow-up date could not be safely prepared.', $error->getMessage());
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('PRIVATE-SENTINEL', $error->getTraceAsString());
            }
        }
        $previousLimit = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '1');
            $this->expectException(InvalidArgumentException::class);
            (new FollowupDate)->parse('через 5 дней', $now);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/followup-prototype-synthetic.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
