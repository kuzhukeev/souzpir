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
}
