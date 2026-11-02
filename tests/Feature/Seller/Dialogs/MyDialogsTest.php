<?php

namespace Tests\Feature\Seller\Dialogs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MyDialogsTest extends TenancyTestCase
{
    public function test_my_dialogs_only_shows_current_manager_and_active_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $mine = $this->addDialog($org->id, $brand->id, $manager->id);
        $waiting = $this->addDialog($org->id, $brand->id, null);
        $foreign = $this->addDialog($org->id, $other->id, $manager->id);
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs?mode=mine')->assertOk()->assertInertia(fn (Assert $page) => $page->has('dialogs', 1)->where('dialogs.0.id', $mine)->where('filters.mode', 'mine'));
        $this->get('/seller/dialogs/'.$waiting.'?mode=mine')->assertNotFound();
        $this->get('/seller/dialogs/'.$foreign.'?mode=mine')->assertNotFound();
        $this->getJson('/seller/dialogs?mode=incorrect')->assertUnprocessable();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs?mode=mine')->assertOk()->assertInertia(fn (Assert $page) => $page->has('dialogs', 0));
    }

    private function addDialog(string $org, string $brand, ?string $assigned): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'state' => '{}', 'mode' => 'manager', 'assigned_user_id' => $assigned, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
