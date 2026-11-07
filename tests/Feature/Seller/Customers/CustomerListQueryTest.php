<?php

namespace Tests\Feature\Seller\Customers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CustomerListQueryTest extends TenancyTestCase
{
    public function test_fifty_customers_do_not_add_a_query_per_customer(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 0; $i < 50; $i++) {
            $contact = (string) Str::ulid();
            DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Клиент '.$i, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $contact, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $queries = 0;
        DB::listen(function ($event) use (&$queries): void {
            if (preg_match('/from\s+"?(conversations|seller_tasks)"?/i', $event->sql)) {
                $queries++;
            }
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/customers')->assertOk()
            ->assertInertia(fn ($page) => $page->has('customers', 20)->where('pagination.total', 50));
        self::assertLessThan(10, $queries);
    }

    public function test_customer_row_lists_all_distinct_channels_and_period_filters_by_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $contact = (string) Str::ulid();
        $widget = (string) Str::ulid();
        $telegram = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Клиент', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('channel_accounts')->insert(['id' => $widget, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'widget', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('channel_accounts')->insert(['id' => $telegram, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'telegram', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $contact, 'channel_account_id' => $widget, 'state' => '{}', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $contact, 'channel_account_id' => $telegram, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $foreignContact = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $foreignContact, 'organization_id' => $org->id, 'brand_id' => $other->id, 'name' => 'Чужой клиент', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customers')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1)
            ->where('customers.0.channels', ['telegram', 'widget'])->where('customers.0.channel', 'telegram'));
        $this->get('/seller/customers?channel=widget')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1));
        $this->get('/seller/customers?period=90')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1)->where('filters.period', '90'));
        $this->get('/seller/customers?period=all')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1));
        $this->get('/seller/customers?period=365')->assertStatus(422);

        [$viewer] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $other->id]);
        $this->get('/seller/customers')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1)
            ->where('customers.0.id', $foreignContact)->where('customers.0.channels', []));
    }
}
