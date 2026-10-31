<?php

namespace Tests\Unit\Shell\Billing;

use App\Shell\Billing\BillingPeriod;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class BillingPeriodTest extends TestCase
{
    public function test_date_range_is_validated_without_accessing_a_database(): void
    {
        foreach ([['0000-01-01', '0000-01-01'], ['9999-12-31', '9999-12-31'], ['2026-02-30', '2026-03-01'], ['2026-04-02', '2026-04-01']] as [$from, $to]) {
            self::assertTrue(Validator::make(compact('from', 'to'), BillingPeriod::rules(), BillingPeriod::messages())->fails());
        }
        self::assertFalse(Validator::make(['from' => '0001-01-01', 'to' => '9999-12-30'], BillingPeriod::rules(), BillingPeriod::messages())->fails());
    }
}
