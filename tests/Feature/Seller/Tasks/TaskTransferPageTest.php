<?php

namespace Tests\Feature\Seller\Tasks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TaskTransferPageTest extends TenancyTestCase
{
    public function test_page_lists_only_active_assignments_and_eligible_people_in_current_brand(): void
    {
        [$owner, $org, $brand, $otherBrand] = $this->cabinet();
        [$recipient] = $this->member($org, 'member', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$other] = $this->member($org, 'member', [$otherBrand->id]);
        $this->enableSeller($org->id, $brand->id);
        $active = $this->task($org->id, $brand->id, $owner->id, 'new');
        $this->task($org->id, $brand->id, $owner->id, 'done');
        $this->task($org->id, $otherBrand->id, $owner->id, 'new');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/tasks/transfer')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Seller/Tasks/Transfer')->has('tasks', 1)->where('tasks.0.id', $active)
            ->has('recipients', 2)->where('recipients', fn ($people) => collect($people)->pluck('id')->contains($recipient->id)
                && ! collect($people)->pluck('id')->contains($viewer->id) && ! collect($people)->pluck('id')->contains($other->id)));
        $this->postJson('/seller/tasks/'.$active.'/transfer', ['assigned_user_id' => $recipient->id])->assertOk();
        $this->assertDatabaseHas('seller_tasks', ['id' => $active, 'assigned_user_id' => $recipient->id]);
    }

    public function test_page_requires_permission_and_enabled_module(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->get('/seller/tasks/transfer')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/tasks/transfer')->assertNotFound();
        $this->enableSeller($org->id, $brand->id);
        $this->flushSession()->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/tasks/transfer')->assertForbidden();
    }

    private function enableSeller(string $organization, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function task(string $organization, string $brand, string $user, string $status): string
    {
        $conversation = (string) Str::ulid();
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $organization, 'brand_id' => $brand, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_tasks')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand, 'conversation_id' => $conversation, 'type' => 'handoff', 'status' => $status, 'assigned_user_id' => $user, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
