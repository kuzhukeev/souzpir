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
        $this->get('/seller/dialogs/mine')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Seller/Dialogs/Mine')->has('dialogs', 2)->where('filters.mode', 'mine'));
        $this->get('/seller/dialogs/mine/'.$foreign)->assertNotFound();
        $this->getJson('/seller/dialogs/mine?sort=invalid')->assertUnprocessable();
        $this->get('/seller/dialogs?mode=mine')->assertOk()->assertInertia(fn (Assert $page) => $page->has('dialogs', 2)->where('mineCounts.active', 2)->where('mineCounts.waiting', 0)->where('filters.mode', 'mine'));
        $this->get('/seller/dialogs/'.$waiting.'?mode=mine')->assertOk();
        $this->get('/seller/dialogs/'.$foreign.'?mode=mine')->assertNotFound();
        $this->getJson('/seller/dialogs?mode=incorrect')->assertUnprocessable();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs?mode=mine')->assertOk()->assertInertia(fn (Assert $page) => $page->has('dialogs', 1)->where('dialogs.0.id', $waiting));
    }

    public function test_counters_and_sort_are_scoped_and_input_is_validated(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id, $other->id], ['sales_manager' => true]);
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $first = $this->addDialog($org->id, $brand->id, $manager->id);
        $waiting = $this->addDialog($org->id, $brand->id, null);
        $closed = $this->addDialog($org->id, $brand->id, $manager->id);
        $foreign = $this->addDialog($org->id, $other->id, $manager->id);
        DB::table('conversations')->where('id', $first)->update(['updated_at' => now()->subDays(2)]);
        DB::table('conversations')->where('id', $closed)->update(['stage' => 'done']);
        foreach ([[$first, $brand->id], [$foreign, $other->id]] as [$id, $brandId]) {
            DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId, 'conversation_id' => $id, 'role' => 'client', 'text' => 'Вопрос', 'meta' => '{}', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        }
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/dialogs?mode=mine&sort=oldest')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('dialogs.0.id', $first)->where('mineCounts.waiting', 1)->where('mineCounts.active', 2)
            ->where('mineCounts.closed_today', 1)->where('filters.sort', 'oldest'));
        $this->getJson('/seller/dialogs?sort=invalid')->assertUnprocessable();
        $this->getJson('/seller/dialogs?search='.str_repeat('x', 101))->assertUnprocessable();
    }

    private function addDialog(string $org, string $brand, ?string $assigned): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'state' => '{}', 'mode' => 'manager', 'assigned_user_id' => $assigned, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
