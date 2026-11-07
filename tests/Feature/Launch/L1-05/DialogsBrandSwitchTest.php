<?php

namespace Tests\Feature\Launch\L1_05;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DialogsBrandSwitchTest extends TenancyTestCase
{
    public function test_brand_switch_is_enabled_and_list_follows_the_selected_brand(): void
    {
        [$owner, $org, $brand, $otherBrand] = $this->cabinet();
        foreach ([$brand, $otherBrand] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $contactA = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contactA, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Бренд A клиент', 'created_at' => now(), 'updated_at' => now()]);
        $dialogA = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialogA, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $contactA, 'mode' => 'manager', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $contactB = (string) Str::ulid();
        DB::table('contacts')->insert(['id' => $contactB, 'organization_id' => $org->id, 'brand_id' => $otherBrand->id, 'name' => 'Бренд B клиент', 'created_at' => now(), 'updated_at' => now()]);
        $dialogB = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialogB, 'organization_id' => $org->id, 'brand_id' => $otherBrand->id, 'contact_id' => $contactB, 'mode' => 'manager', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/dialogs')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('dialogs', 1)->where('dialogs.0.id', $dialogA)->where('brand.id', $brand->id));
        $this->actingAs($owner)->withSession(['brand_id' => $otherBrand->id])->get('/seller/dialogs')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('dialogs', 1)->where('dialogs.0.id', $dialogB)->where('brand.id', $otherBrand->id));
    }
}
