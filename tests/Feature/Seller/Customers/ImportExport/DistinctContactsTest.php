<?php

namespace Tests\Feature\Seller\Customers\ImportExport;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DistinctContactsTest extends TenancyTestCase
{
    public function test_distinct_pairs_are_hidden_only_for_their_brand_and_require_permission(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $pairs = [];
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            for ($i = 0; $i < 2; $i++) {
                $id = (string) Str::ulid();
                $pairs[$item->id][] = $id;
                DB::table('contacts')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'name' => 'Клиент '.$i, 'phone' => '+79991234567', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        [$a, $b] = $pairs[$brand->id];
        [$c, $d] = $pairs[$other->id];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/customer-duplicates')->assertOk()->assertInertia(fn ($page) => $page->has('pairs', 1));
        $this->post('/seller/customer-duplicates/distinct', ['first' => $b, 'second' => $a])->assertRedirect();
        $this->post('/seller/customer-duplicates/distinct', ['first' => $a, 'second' => $b])->assertRedirect();
        $this->assertDatabaseCount('seller_distinct_contact_pairs', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.customer.distinct']);
        $this->get('/seller/customer-duplicates')->assertOk()->assertInertia(fn ($page) => $page->has('pairs', 0));
        $this->post('/seller/customer-duplicates/distinct', ['first' => $a, 'second' => $a])->assertSessionHasErrors('first');
        $this->post('/seller/customer-duplicates/distinct', ['first' => $a, 'second' => $c])->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/customer-duplicates')->assertOk()->assertInertia(fn ($page) => $page->has('pairs', 1));
        [$viewer] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $other->id]);
        $this->post('/seller/customer-duplicates/distinct', ['first' => $c, 'second' => $d])->assertForbidden();
    }
}
