<?php

namespace Tests\Feature\Seller\Settings\Schedule;

use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Runtime\BrandRuntimeSchedule;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\SellerRuntime\Turns\ProtectedFollowupFixtures;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AssignmentE606CTest extends TenancyTestCase
{
    use ProtectedFollowupFixtures;

    private function conversation(string $organization, string $brand): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $organization, 'brand_id' => $brand, 'mode' => 'manager', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_rotation_is_per_brand_and_repeat_does_not_advance_it(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id, $other->id], ['sales_manager' => true]);
        $ids = [$owner->id, $manager->id];
        sort($ids);
        $settings = ['assignment' => ['method' => 'round_robin', 'members' => array_map(fn ($id) => ['id' => $id, 'enabled' => true, 'max_active' => null], $ids)]];
        $service = app(BrandRuntimeSchedule::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $first = $this->conversation($org->id, $brand->id);
        $second = $this->conversation($org->id, $brand->id);
        $third = $this->conversation($org->id, $other->id);
        $service->assignWaiting($tenant, $first, $settings);
        $service->assignWaiting($tenant, $first, $settings);
        $service->assignWaiting($tenant, $second, $settings);
        $service->assignWaiting(new TenantContext($org->id, $other->id, source: 'job'), $third, $settings);
        $this->assertDatabaseHas('conversations', ['id' => $first, 'assigned_user_id' => $ids[0]]);
        $this->assertDatabaseHas('conversations', ['id' => $second, 'assigned_user_id' => $ids[1]]);
        $this->assertDatabaseHas('conversations', ['id' => $third, 'assigned_user_id' => $ids[0]]);
        $this->assertDatabaseHas('seller_assignment_rotation', ['brand_id' => $brand->id, 'last_user_id' => $ids[1]]);
    }

    public function test_fixed_first_taken_and_active_limits(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$foreign] = $this->member($org, 'member', [$other->id], ['sales_manager' => true]);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $service = app(BrandRuntimeSchedule::class);
        $member = ['id' => $owner->id, 'enabled' => true, 'max_active' => 1];
        $fixed = ['assignment' => ['method' => 'fixed', 'fixed' => $owner->id, 'members' => [$member]]];
        $one = $this->conversation($org->id, $brand->id);
        $two = $this->conversation($org->id, $brand->id);
        $service->assignWaiting($tenant, $one, $fixed);
        $service->assignWaiting($tenant, $two, $fixed);
        $this->assertDatabaseHas('conversations', ['id' => $one, 'assigned_user_id' => $owner->id]);
        $this->assertDatabaseHas('conversations', ['id' => $two, 'assigned_user_id' => null]);
        DB::table('conversations')->where('id', $one)->update(['stage' => 'done']);
        $service->assignWaiting($tenant, $two, $fixed);
        $this->assertDatabaseHas('conversations', ['id' => $two, 'assigned_user_id' => $owner->id]);
        $waiting = $this->conversation($org->id, $brand->id);
        $service->assignWaiting($tenant, $waiting, ['assignment' => ['method' => 'first_taken', 'members' => [$member]]]);
        $this->assertDatabaseHas('conversations', ['id' => $waiting, 'assigned_user_id' => null]);
        $service->assignWaiting($tenant, $waiting, ['assignment' => ['method' => 'fixed', 'fixed' => $foreign->id, 'members' => [['id' => $foreign->id, 'enabled' => true, 'max_active' => null]]]]);
        $this->assertDatabaseHas('conversations', ['id' => $waiting, 'assigned_user_id' => null]);
    }

    public function test_assign_waiting_honors_conversation_channel_and_member_hours(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $service = app(BrandRuntimeSchedule::class);
        $widget = (string) Str::ulid();
        DB::table('channel_accounts')->insert(['id' => $widget, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'widget', 'created_at' => now(), 'updated_at' => now()]);
        $outOfChannel = ['assignment' => ['method' => 'round_robin', 'members' => [['id' => $owner->id, 'enabled' => true, 'max_active' => null, 'channels' => ['telegram']]]]];
        $conversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'mode' => 'manager', 'channel_account_id' => $widget, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $service->assignWaiting($tenant, $conversation, $outOfChannel);
        $this->assertDatabaseHas('conversations', ['id' => $conversation, 'assigned_user_id' => null]);
        $matchingChannel = ['assignment' => ['method' => 'round_robin', 'members' => [['id' => $owner->id, 'enabled' => true, 'max_active' => null, 'channels' => ['widget']]]]];
        $service->assignWaiting($tenant, $conversation, $matchingChannel);
        $this->assertDatabaseHas('conversations', ['id' => $conversation, 'assigned_user_id' => $owner->id]);
        $outOfHours = ['assignment' => ['method' => 'round_robin', 'members' => [['id' => $owner->id, 'enabled' => true, 'max_active' => null, 'hours' => ['start' => '00:00', 'end' => '00:01']]]]];
        $second = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $second, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'mode' => 'manager', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $service->assignWaiting($tenant, $second, $outOfHours);
        $this->assertDatabaseHas('conversations', ['id' => $second, 'assigned_user_id' => null]);
    }

    public function test_fixed_channels_route_by_conversation_channel_and_fall_back_to_default(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $service = app(BrandRuntimeSchedule::class);
        $widget = (string) Str::ulid();
        DB::table('channel_accounts')->insert(['id' => $widget, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'widget', 'created_at' => now(), 'updated_at' => now()]);
        $members = [['id' => $owner->id, 'enabled' => true, 'max_active' => null], ['id' => $manager->id, 'enabled' => true, 'max_active' => null]];
        $settings = ['assignment' => ['method' => 'fixed', 'fixed' => $owner->id, 'fixed_channels' => ['widget' => $manager->id], 'members' => $members]];
        $widgetConversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $widgetConversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'mode' => 'manager', 'channel_account_id' => $widget, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $service->assignWaiting($tenant, $widgetConversation, $settings);
        $this->assertDatabaseHas('conversations', ['id' => $widgetConversation, 'assigned_user_id' => $manager->id]);
        $fallback = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $fallback, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'mode' => 'manager', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $service->assignWaiting($tenant, $fallback, $settings);
        $this->assertDatabaseHas('conversations', ['id' => $fallback, 'assigned_user_id' => $owner->id]);
    }

    public function test_global_suggest_applies_to_existing_bot_without_sending(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_schedule_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'payload' => json_encode(['mode' => 'suggest']), 'created_at' => now(), 'updated_at' => now()]);
        [$conversation, $message] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot', 'version' => 1]);

            return [$conversation, Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Уточните условия', 'meta' => []])];
        });
        config(['runtime.ai_driver' => 'fake']);
        $member = DB::table('memberships')->where('organization_id', $org->id)->where('user_id', $owner->id)->value('id');
        $this->seedProtected(new TenantContext($org->id, $brand->id, $owner->id, $member));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = ['conversation_id' => $conversation->id, 'message_id' => $message->id, 'text' => $message->text, 'idempotency_key' => 'suggest-existing', 'expected_version' => 1];
        $this->postJson('/seller/turns/runtime', array_replace($input, ['expected_version' => 0]));
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'mode' => 'bot', 'version' => 1]);
        $this->postJson('/seller/turns/runtime', $input)->assertOk()->assertJsonPath('data.status', 'suggested');
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'mode' => 'suggest', 'version' => 2]);
        self::assertSame(0, DB::table('messages')->where('conversation_id', $conversation->id)->where('role', 'agent')->count());
        $this->postJson('/seller/turns/runtime', $input)->assertOk()->assertJsonPath('data.status', 'suggested');
    }
}
