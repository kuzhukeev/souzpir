<?php

namespace Tests\Feature\Seller\Settings\Schedule;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AssignmentReminderE606ETest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function schedule(string $org, string $brand, ?int $minutes, string $target): void
    {
        DB::table('seller_schedule_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand,
            'payload' => json_encode(['assignment' => ['notify_minutes' => $minutes, 'notify_target' => $target]], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function waitingConversation(string $org, string $brand, Carbon $handedOffAt): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'mode' => 'manager',
            'handed_off_at' => $handedOffAt, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_notifies_all_managers_once_past_threshold(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->schedule($org->id, $brand->id, 5, 'all_managers');
        $conversation = $this->waitingConversation($org->id, $brand->id, now()->subMinutes(10));
        $this->artisan('seller:monitor-handoffs')->assertSuccessful();
        $this->assertDatabaseHas('seller_tasks', ['conversation_id' => $conversation, 'type' => 'assignment_reminder', 'assigned_user_id' => null]);
        $this->artisan('seller:monitor-handoffs')->assertSuccessful();
        $this->assertDatabaseCount('seller_tasks', 1);
    }

    public function test_notifies_owner_when_target_is_owner(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->schedule($org->id, $brand->id, 5, 'owner');
        $this->waitingConversation($org->id, $brand->id, now()->subMinutes(10));
        $this->artisan('seller:monitor-handoffs')->assertSuccessful();
        $this->assertDatabaseHas('seller_tasks', ['type' => 'assignment_reminder', 'assigned_user_id' => $owner->id]);
    }

    public function test_skips_before_threshold_and_disabled_timer(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->schedule($org->id, $brand->id, 30, 'all_managers');
        $this->waitingConversation($org->id, $brand->id, now()->subMinutes(5));
        $this->schedule($org->id, $other->id, null, 'all_managers');
        $this->waitingConversation($org->id, $other->id, now()->subMinutes(10));
        $this->artisan('seller:monitor-handoffs')->assertSuccessful();
        $this->assertDatabaseCount('seller_tasks', 0);
    }

    public function test_skips_once_conversation_is_assigned(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->schedule($org->id, $brand->id, 5, 'all_managers');
        $conversation = $this->waitingConversation($org->id, $brand->id, now()->subMinutes(10));
        DB::table('conversations')->where('id', $conversation)->update(['assigned_user_id' => $owner->id, 'taken_at' => now()]);
        $this->artisan('seller:monitor-handoffs')->assertSuccessful();
        $this->assertDatabaseCount('seller_tasks', 0);
    }
}
