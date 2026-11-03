<?php

namespace Tests\Feature\Seller\Customers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CustomersOverviewTest extends TenancyTestCase
{
    public function test_pagination_best_outcome_and_brand_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $contact = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Уникальный клиент', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['full_contact', now()->subDay()], ['stale', now()]] as [$outcome, $date]) {
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $contact, 'outcome' => $outcome, 'state' => '{}', 'created_at' => $date, 'updated_at' => $date]);
        }
        for ($i = 0; $i < 20; $i++) {
            DB::table('contacts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Клиент '.$i, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('contacts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id, 'name' => 'Чужой', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customers')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 20)->where('pagination.total', 21)->where('pagination.lastPage', 2));
        $this->get('/seller/customers?page=2')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1)->where('pagination.page', 2));
        $this->get('/seller/customers?search=Уникальный')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 1)->where('customers.0.outcome', 'stale')->where('customers.0.best_outcome', 'full_contact')->where('pagination.total', 1));
        $this->get('/seller/customers?page=0')->assertSessionHasErrors('page');
        $this->get('/seller/customers?search=Чужой')->assertOk()->assertInertia(fn ($page) => $page->has('customers', 0));
        [$viewer] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $other->id])->get('/seller/customers/'.$contact)->assertNotFound();
    }
}
