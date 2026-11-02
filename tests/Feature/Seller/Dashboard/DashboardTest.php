<?php

namespace Tests\Feature\Seller\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DashboardTest extends TenancyTestCase
{
    private function enable(string $organization, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $brand,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function conversation(string $organization, string $brand, bool $waiting): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand, 'stage' => 'done',
            'state' => json_encode(['slots' => ['email' => 'client@example.test']]), 'mode' => $waiting ? 'manager' : 'bot',
            'handed_off_at' => $waiting ? now() : null, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_summary_and_attention_are_scoped_to_selected_brand(): void
    {
        [$owner, $organization, $brand, $other] = $this->cabinet();
        $this->enable($organization->id, $brand->id);
        $this->enable($organization->id, $other->id);
        $conversation = $this->conversation($organization->id, $brand->id, true);
        $this->conversation($organization->id, $other->id, true);
        DB::table('seller_tasks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'brand_id' => $brand->id, 'conversation_id' => $conversation, 'type' => 'promise', 'status' => 'new',
            'due_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller?days=7')->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Dashboard/Index')
            ->where('dashboard.days', 7)->where('dashboard.total', 1)->where('dashboard.contact', 1)
            ->where('dashboard.handoff', 1)->where('dashboard.attention.0.count', 1)->where('dashboard.attention.1.count', 1));
        $this->get('/seller/attention')->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Dashboard/Attention')
            ->has('dashboard.attention', 2));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.total', 1)->has('dashboard.attention', 1));
    }

    public function test_empty_access_and_invalid_period(): void
    {
        [$owner, $organization, $brand, $other] = $this->cabinet();
        $this->enable($organization->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller')->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.total', 0)->has('dashboard.attention', 0));
        $this->get('/seller?days=0')->assertSessionHasErrors('days');
        $this->get('/seller/attention?days=abc')->assertSessionHasErrors('days');
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller')->assertOk();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id]);
        $this->get('/seller')->assertNotFound();
    }
}
