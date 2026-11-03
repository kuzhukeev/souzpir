<?php

namespace Tests\Feature\Shell\Billing;

use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Shell\Plans\Contracts\UsageSnapshot;
use App\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DailyUsageE107BTest extends TenancyTestCase
{
    public function test_daily_rows_csv_and_access_are_scoped_to_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->app->instance(UsageReader::class, new class($brand->id) implements UsageReader
        {
            public function __construct(private string $expectedBrand) {}

            public function read(TenantContext $context, UsageQuery $query): UsageSnapshot
            {
                $value = $context->brandId === $this->expectedBrand && $query->from->format('Y-m-d') === '2026-10-01' ? 2 : 0;

                return new UsageSnapshot($query->from, ['seller.dialogues' => ['value' => $value, 'unit' => 'dialogues', 'status' => 'ready']]);
            }
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $url = '?from=2026-10-01&to=2026-10-02';
        $this->get('/billing/usage'.$url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('daily.rows', 1)->where('daily.rows.0.value', 2)->where('daily.rows.0.brand', $brand->name));
        $csv = $this->get('/billing/usage/export'.$url)->assertOk()->streamedContent();
        self::assertStringContainsString($brand->name, $csv);
        self::assertStringContainsString('2026-10-01', $csv);
        self::assertStringNotContainsString($other->name, $csv);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/billing/usage'.$url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('daily.rows', 0));
        $this->get('/billing/usage/export?from=2026-10-02&to=2026-10-01')->assertSessionHasErrors('to');
        $this->get('/billing/usage/export?from=2026-01-01&to=2026-12-31')->assertStatus(422);
        [$member] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->get('/billing/usage/export'.$url)->assertForbidden();
    }
}
