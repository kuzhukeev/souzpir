<?php

namespace Tests\Feature\Shadow;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ShadowComparisonTest extends TenancyTestCase
{
    public function test_command_refuses_to_record_while_shadow_flag_is_disabled(): void
    {
        [, $organization, $brand] = $this->cabinet();

        $this->artisan('shadow:record', [
            'brand' => $brand->id, 'channel' => 'telegram', 'inbound' => 'Сколько стоит доставка?',
            'baseline' => 'Доставка 300 рублей.', 'candidate' => 'Доставка 300 рублей.',
        ])->assertExitCode(1);
        self::assertSame(0, DB::table('shadow_comparisons')->where('organization_id', $organization->id)->count());
    }

    public function test_command_records_match_and_mismatch_and_report_lists_only_discrepancies(): void
    {
        config(['features.E9-03.defaults.shadow_enabled' => true]);
        [, $organization, $brand] = $this->cabinet();

        $this->artisan('shadow:record', [
            'brand' => $brand->id, 'channel' => 'telegram', 'inbound' => 'Сколько стоит доставка?',
            'baseline' => 'Доставка 300 рублей.', 'candidate' => 'Доставка 300 рублей.',
        ])->assertExitCode(0);
        $this->artisan('shadow:record', [
            'brand' => $brand->id, 'channel' => 'telegram', 'inbound' => 'Когда привезёте?',
            'baseline' => 'Привезём завтра.', 'candidate' => 'Привезём через неделю.',
        ])->assertExitCode(0);

        self::assertSame(2, DB::table('shadow_comparisons')->where('organization_id', $organization->id)->where('brand_id', $brand->id)->count());
        self::assertSame(1, DB::table('shadow_comparisons')->where('brand_id', $brand->id)->where('verdict', 'mismatch')->count());

        $this->artisan('shadow:report', ['brand' => $brand->id])
            ->expectsOutputToContain('Всего сравнений: 2')
            ->expectsOutputToContain('Расхождений: 1')
            ->expectsOutputToContain('Когда привезёте?')
            ->doesntExpectOutputToContain('Сколько стоит доставка?')
            ->assertExitCode(0);
    }

    public function test_report_does_not_mix_comparisons_from_another_brand(): void
    {
        config(['features.E9-03.defaults.shadow_enabled' => true]);
        [, , $brandA, $brandB] = $this->cabinet();

        $this->artisan('shadow:record', [
            'brand' => $brandA->id, 'channel' => 'telegram', 'inbound' => 'A', 'baseline' => 'X', 'candidate' => 'Y',
        ])->assertExitCode(0);
        $this->artisan('shadow:record', [
            'brand' => $brandB->id, 'channel' => 'telegram', 'inbound' => 'B', 'baseline' => 'X', 'candidate' => 'X',
        ])->assertExitCode(0);

        $this->artisan('shadow:report', ['brand' => $brandA->id])
            ->expectsOutputToContain('Всего сравнений: 1')
            ->assertExitCode(0);
    }
}
