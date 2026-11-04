<?php

namespace Tests\Feature\Seller\Dashboard;

use App\Seller\Dashboard\DashboardQuery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DashboardTablesE705DTest extends TenancyTestCase
{
    public function test_channels_problems_deltas_and_access_are_brand_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $channel = (string) Str::ulid();
        DB::table('channel_accounts')->insert(['id' => $channel, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'widget', 'name' => 'Сайт', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['email' => 'a@example.test'], []] as $slots) {
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'channel_account_id' => $channel, 'stage' => 'open', 'mode' => 'bot', 'state' => json_encode(['slots' => $slots]), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[now(), '[{"type":"unknown_fact"},{"type":"unknown_fact"}]'], [now(), '[]'], [now()->subDays(9), '[{"type":"unknown_fact"}]']] as [$created, $issues]) {
            DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'scores' => '{}', 'issues' => $issues, 'average' => 4, 'created_at' => $created, 'updated_at' => $created]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller?days=7')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.channels.0.dialogs', 2)->where('dashboard.channels.0.contacts', 1)->where('dashboard.channels.0.conversion', 50)->where('dashboard.issue_details.0.share', 50)->where('dashboard.issue_details.0.delta', -50)->where('dashboard.delta.total', 2));
        $this->get('/seller?days=abc')->assertSessionHasErrors('days');
        $this->get('/seller?days=0')->assertSessionHasErrors('days');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller?days=7')->assertOk();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id]);
        $this->get('/seller?days=7')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.total', 0)->has('dashboard.channels', 0)->has('dashboard.issue_details', 0));
    }

    public function test_initial_database_error_returns_retry_screen(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $query = Mockery::mock(app(DashboardQuery::class));
        $query->shouldReceive('read')->once()->andThrow(new QueryException('pgsql', 'select 1', [], new RuntimeException('failure')));
        $this->app->instance(DashboardQuery::class, $query);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller')->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Dashboard/Index')->where('dashboard', null)->where('initialLoadError', true));
    }
}
