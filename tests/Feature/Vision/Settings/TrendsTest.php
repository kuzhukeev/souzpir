<?php

namespace Tests\Feature\Vision\Settings;

use App\Tenancy\TenantContext;
use App\Vision\Settings\Trends;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TrendsTest extends TenancyTestCase
{
    public function test_completed_actions_use_exact_neighbor_report_times(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        foreach ([
            [$brand->id, 'На первой границе', '2026-10-01 09:00:00+00'],
            [$brand->id, 'Утром последнего дня', '2026-10-15 10:00:00+00'],
            [$brand->id, 'На второй границе', '2026-10-15 15:00:00+00'],
            [$brand->id, 'После второго замера', '2026-10-15 15:00:01+00'],
            [$other->id, 'Чужое действие', '2026-10-15 12:00:00+00'],
        ] as [$brandId, $title, $completedAt]) {
            DB::table('actions')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
                'brand_id' => $brandId, 'title' => $title, 'status' => 'done', 'completed_at' => substr($completedAt, 0, 10),
                'created_at' => $completedAt, 'updated_at' => $completedAt]);
        }
        $rows = [
            ['id' => 'first', 'date' => '2026-10-01 09:00:00+00', 'share' => 20],
            ['id' => 'second', 'date' => '2026-10-15 15:00:00+00', 'share' => 25],
        ];
        $actual = app(Trends::class)->withActions(new TenantContext($org->id, $brand->id), $rows);
        $this->assertSame([], $actual[0]['actions']);
        $this->assertSame(['Утром последнего дня', 'На второй границе'], $actual[1]['actions']);
        $this->assertSame(5.0, $actual[1]['share_change_pp']);
    }

    public function test_empty_trends_are_scoped_to_selected_brand_and_require_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/trends')->assertOk()->assertInertia(fn ($page) => $page->component('Vision/Settings/Trends')->has('rows', 0)->where('error', false));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/trends')->assertOk()->assertInertia(fn ($page) => $page->has('rows', 0));
        $this->post('/logout')->assertRedirect();
        $this->get('/vision/trends')->assertRedirect();
    }
}
