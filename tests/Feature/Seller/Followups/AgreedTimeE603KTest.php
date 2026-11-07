<?php

namespace Tests\Feature\Seller\Followups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AgreedTimeE603KTest extends TenancyTestCase
{
    public function test_only_agreed_contact_time_is_shown_as_known_and_brand_isolated(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $dialog = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach (['next_contact_at', 'silence'] as $reason) {
                DB::table('seller_followup_candidates')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $dialog, 'reason' => $reason, 'source_at' => '2026-11-06 10:00:00', 'sequence' => 1, 'text' => $reason, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $items = collect($this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/followups')->assertOk()->inertiaProps('items'))->keyBy('text');
        self::assertCount(2, $items);
        self::assertNotNull($items['next_contact_at']->scheduled_at ?? $items['next_contact_at']['scheduled_at']);
        self::assertNull($items['silence']->scheduled_at ?? $items['silence']['scheduled_at'] ?? null);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/followups')->assertForbidden();
    }
}
