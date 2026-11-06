<?php

namespace Tests\Feature\Seller\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ChannelAttentionE509BTest extends TenancyTestCase
{
    public function test_disabled_channel_without_confirmed_time_is_scoped_and_not_claimed_over_a_day(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('channel_accounts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'widget', 'name' => 'Сайт', 'status' => 'disabled', 'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(3)]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/attention')->assertOk()->assertInertia(fn ($page) => $page->has('dashboard.attention', 1)->where('dashboard.attention.0.kind', 'channels')->where('dashboard.attention.0.title', 'Каналы выключены')->where('dashboard.attention.0.detail', '1 · Время отключения неизвестно'));
        $this->get('/seller/attention?days=abc')->assertSessionHasErrors('days');
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/attention')->assertOk()->assertInertia(fn ($page) => $page->has('dashboard.attention', 0));
        $this->post('/logout');
        $this->get('/seller/attention')->assertRedirect();
    }
}
