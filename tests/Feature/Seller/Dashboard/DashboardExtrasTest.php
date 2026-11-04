<?php

namespace Tests\Feature\Seller\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DashboardExtrasTest extends TenancyTestCase
{
    public function test_quality_topics_deltas_and_manager_load_are_scoped(): void
    {
        [$owner,$org,$brand,$other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $current = (string) Str::ulid();
        foreach ([[$current, $brand->id, now()], [(string) Str::ulid(), $brand->id, now()->subDays(9)], [(string) Str::ulid(), $other->id, now()]] as [$id,$brandId,$created]) {
            DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brandId, 'state' => json_encode(['slots' => ['email' => 'test@example.test']]), 'mode' => 'manager', 'stage' => 'done', 'assigned_user_id' => $owner->id, 'created_at' => $created, 'updated_at' => now()]);
        }
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $current, 'role' => 'agent', 'text' => 'Ответ', 'meta' => json_encode(['intent' => 'delivery']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'scores' => '{}', 'issues' => '[{"type":"wrong_fact"}]', 'average' => 4, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller?days=7')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.total', 1)->where('dashboard.delta.total', 0)->where('dashboard.quality', 4)->where('dashboard.issues.wrong_fact', 1)->where('dashboard.topics.delivery', 1)->where('dashboard.managers.0.total', 1));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller?days=7')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.quality', null)->where('dashboard.managers.0.total', 1)->has('dashboard.topics', 0));
        $this->get('/seller?days=3')->assertSessionHasErrors('days');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller?days=7')->assertOk();
    }
}
