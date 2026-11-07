<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DialogFiltersTest extends TenancyTestCase
{
    public function test_filters_pagination_and_brand_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$otherManager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $channel = (string) Str::ulid();
        DB::table('channel_accounts')->insert(['id' => $channel, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'telegram', 'created_at' => now(), 'updated_at' => now()]);
        $first = '';
        for ($i = 0; $i < 52; $i++) {
            $id = (string) Str::ulid();
            if ($i === 0) {
                $first = $id;
            }
            DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'channel_account_id' => $channel, 'stage' => $i === 0 ? 'need' : 'open', 'state' => '{}', 'mode' => 'manager', 'assigned_user_id' => $i === 0 ? $manager->id : $otherManager->id, 'created_at' => now(), 'updated_at' => now()->subMinutes(52 - $i)]);
        }
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id, 'state' => '{}', 'mode' => 'bot', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $first, 'role' => 'agent', 'text' => 'Последнее сообщение', 'created_at' => now()->addMinute(), 'updated_at' => now()->addMinute()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs')->assertOk()->assertInertia(fn (Assert $page) => $page->has('dialogs', 50)->where('pagination.total', 52)->where('pagination.pages', 2)->where('dialogs.0.id', $first));
        $this->get('/seller/dialogs?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page->has('dialogs', 2)->where('pagination.page', 2));
        $this->get('/seller/dialogs/'.$first.'?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page->where('dialog.id', $first));
        $this->get('/seller/dialogs?channel=telegram&stage=need&manager='.$manager->id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('dialogs', 1)->where('dialogs.0.id', $first));
        $this->getJson('/seller/dialogs?page=0')->assertUnprocessable();
        $this->getJson('/seller/dialogs?stage=invalid')->assertUnprocessable();
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs?manager='.$otherManager->id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('dialogs', 0)->has('managerOptions', 1));
        $this->get('/seller/dialogs/'.$first)->assertOk();
    }
}
