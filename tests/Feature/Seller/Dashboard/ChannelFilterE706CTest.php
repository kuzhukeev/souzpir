<?php

namespace Tests\Feature\Seller\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ChannelFilterE706CTest extends TenancyTestCase
{
    public function test_channel_filters_current_brand_and_rejects_invalid_values(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $first = (string) Str::ulid();
        $second = (string) Str::ulid();
        $foreign = (string) Str::ulid();
        foreach ([[$first, $brand], [$second, $brand], [$foreign, $other]] as [$id, $item]) {
            DB::table('channel_accounts')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'type' => 'widget', 'name' => $id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'channel_account_id' => $id, 'stage' => 'open', 'state' => '{}', 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller?channel='.$first)->assertOk()->assertInertia(fn ($page) => $page->where('dashboard.channel', $first)->where('dashboard.total', 1));
        $this->get('/seller?channel='.$foreign)->assertNotFound();
        $this->get('/seller?channel=bad')->assertSessionHasErrors('channel');
        $this->post('/logout')->assertRedirect();
        $this->get('/seller')->assertRedirect('/login');
    }
}
