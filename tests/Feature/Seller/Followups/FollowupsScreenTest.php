<?php

namespace Tests\Feature\Seller\Followups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FollowupsScreenTest extends TenancyTestCase
{
    public function test_screen_and_cancellation_respect_brand_and_permissions(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $ids = [];
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $conversation = (string) Str::ulid();
            $id = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_followup_candidates')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $conversation, 'reason' => 'silence', 'source_at' => now()->subDay(), 'sequence' => 1, 'text' => 'Тестовое напоминание', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = $id;
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/followups')->assertOk()->assertInertia(fn ($page) => $page->where('counts.scheduled', 1)->where('items.0.id', $ids[0]));
        $this->get('/seller/followups?days=5')->assertSessionHasErrors('days');
        $this->post('/seller/followups/'.$ids[1].'/cancel')->assertNotFound();
        $this->post('/seller/followups/'.$ids[0].'/cancel')->assertRedirect();
        $this->assertDatabaseHas('seller_followup_candidates', ['id' => $ids[0], 'status' => 'cancelled']);
        $this->post('/seller/followups/'.$ids[0].'/cancel')->assertStatus(422);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/followups')->assertForbidden();
        $this->post('/seller/followups/'.$ids[0].'/cancel')->assertForbidden();
    }
}
