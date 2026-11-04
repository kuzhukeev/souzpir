<?php

namespace Tests\Feature\Seller\Followups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RepliesAfterTest extends TenancyTestCase
{
    public function test_only_conversations_with_later_client_messages_are_counted_per_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $dialog = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_followup_candidates')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $dialog, 'reason' => 'silence', 'source_at' => now()->subDays(3), 'sequence' => 1, 'text' => 'Напоминание', 'status' => 'completed', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDay()]);
            foreach ([now()->subDays(2), now()->subHours(12), now()->subHours(6)] as $date) {
                DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $dialog, 'role' => 'client', 'text' => 'Сообщение', 'created_at' => $date, 'updated_at' => $date]);
            }
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/followups')->assertOk()->assertInertia(fn ($page) => $page->where('counts.replies_after_test', 1));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/followups')->assertForbidden();
    }
}
