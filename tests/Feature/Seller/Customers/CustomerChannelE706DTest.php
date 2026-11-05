<?php

namespace Tests\Feature\Seller\Customers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CustomerChannelE706DTest extends TenancyTestCase
{
    public function test_channel_filter_uses_channel_codes_and_rejects_invalid_values(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$brand, 'telegram', 'Телеграм'], [$brand, 'widget', 'Сайт'], [$other, 'telegram', 'Другой бренд']] as [$current, $type, $name]) {
            $channel = (string) Str::ulid();
            $contact = (string) Str::ulid();
            DB::table('channel_accounts')->insert(['id' => $channel, 'organization_id' => $org->id, 'brand_id' => $current->id, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org->id, 'brand_id' => $current->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $current->id, 'contact_id' => $contact, 'channel_account_id' => $channel, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->get('/seller/customers')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customers?channel=telegram')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1)->where('customers.0.name', 'Телеграм'));
        $this->get('/seller/customers?channel=widget')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1)->where('customers.0.name', 'Сайт'));
        $this->get('/seller/customers')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 2));
        $this->get('/seller/customers?channel=invalid')->assertUnprocessable();
        $this->getJson('/seller/customers?channel=invalid')->assertUnprocessable();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/customers?channel=widget')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1));
    }
}
