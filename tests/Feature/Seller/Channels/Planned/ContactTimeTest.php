<?php

namespace Tests\Feature\Seller\Channels\Planned;

use App\Seller\Channels\Planned\ContactTime;
use PHPUnit\Framework\TestCase;

final class ContactTimeTest extends TestCase
{
    public function test_only_complete_unambiguous_dates_are_accepted(): void
    {
        $parser = new ContactTime;
        self::assertSame('2026-11-07T09:30:00+00:00', $parser->parse('2026-11-07 12:30', 'Europe/Moscow')?->toIso8601String());
        self::assertSame('2026-11-07T09:30:00+00:00', $parser->parse('2026-11-07T11:30:00+02:00', 'Europe/Moscow')?->toIso8601String());
        self::assertSame('2026-11-07T09:30:00+00:00', $parser->parse('2026-11-07T09:30Z', 'Europe/Moscow')?->toIso8601String());
        foreach (['tomorrow', '2026-11-07', '2026-02-30T12:30', '2026-11-07T25:00', '', null] as $value) {
            self::assertNull($parser->parse($value, 'Europe/Moscow'));
        }
    }
}
