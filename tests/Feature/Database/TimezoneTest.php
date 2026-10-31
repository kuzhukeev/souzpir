<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TimezoneTest extends TestCase
{
    public function test_postgres_session_uses_utc_for_framework_timestamps(): void
    {
        self::assertSame('UTC', DB::selectOne("select current_setting('TimeZone') as zone")->zone);
        $instant = now()->startOfSecond();
        $row = DB::selectOne('select extract(epoch from ?::timestamptz) as value', [$instant]);
        self::assertSame($instant->timestamp, (int) $row->value);
    }
}
