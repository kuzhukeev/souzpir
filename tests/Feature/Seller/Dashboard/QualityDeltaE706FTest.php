<?php

namespace Tests\Feature\Seller\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class QualityDeltaE706FTest extends TenancyTestCase
{
    public function test_quality_compares_equal_previous_period_and_isolates_brands(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$brand, 4.5, now()], [$brand, 3.5, now()->subDays(8)], [$other, 1, now()->subDays(8)]] as [$item, $average, $created]) {
            DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'scores' => '{}', 'issues' => '[]', 'average' => $average, 'created_at' => $created, 'updated_at' => $created]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller?days=7')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.quality', 4.5)->where('dashboard.delta.quality', 1));
        $series = $this->get('/seller?days=7')->assertOk()->viewData('page')['props']['dashboard']['daily_quality'];
        self::assertSame([4.5], array_values(array_filter($series, fn ($value) => $value !== null)));
        $this->get('/seller?days=90')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.delta.quality', null));
        $this->get('/seller?days=0')->assertSessionHasErrors('days');
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller?days=7')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.quality', null)->where('dashboard.delta.quality', null));
    }
}
