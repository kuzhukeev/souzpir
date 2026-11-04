<?php

namespace Tests\Feature\Seller\Dashboard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DashboardSeriesTest extends TenancyTestCase
{
    public function test_contacts_manager_counts_and_waiting_are_brand_scoped(): void
    {
        Carbon::setTestNow('2026-11-04 12:00:00 UTC');
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->conversation($org->id, $brand->id, $owner->id, 'done', ['email' => 'a@example.test'], now()->subMinutes(30));
        $this->conversation($org->id, $brand->id, $owner->id, 'open', [], null);
        $this->conversation($org->id, $brand->id, null, 'open', [], now()->subMinutes(32));
        $this->conversation($org->id, $other->id, null, 'open', ['email' => 'other@example.test'], now()->subMinutes(60));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller?days=7')->assertOk()->assertInertia(fn ($page) => $page
            ->where('dashboard.daily.2026-11-04', 3)->where('dashboard.daily_contacts.2026-11-04', 1)
            ->where('dashboard.managers.0.handed', 1)->where('dashboard.managers.0.closed', 1)
            ->where('dashboard.attention.0.count', 1)->where('dashboard.attention.0.detail', 'Ожидание до 32 мин · 1'));
        $this->get('/seller?days=0')->assertSessionHasErrors('days');
        $this->get('/seller?days=abc')->assertSessionHasErrors('days');
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller?days=7')->assertOk();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller?days=7')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.daily_contacts.2026-11-04', 1)->where('dashboard.total', 1));
        Carbon::setTestNow();
    }

    private function conversation(string $org, string $brand, ?string $assignee, string $stage, array $slots, mixed $handoff): void
    {
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'mode' => 'manager', 'assigned_user_id' => $assignee,
            'stage' => $stage, 'state' => json_encode(['slots' => $slots]), 'handed_off_at' => $handoff, 'created_at' => now()->subHour(), 'updated_at' => now()]);
    }
}
