<?php

namespace Tests\Feature\Seller\Settings\Tools;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class HandoffTimeoutRuntimeE604FTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_notification_and_fallback_fire_once_per_timeout(): void
    {
        Carbon::setTestNow('2026-11-07 12:00:00+00');
        try {
            Queue::fake();
            [$owner, $org, $brand] = $this->cabinet();
            $this->enable($org->id, $brand->id);
            $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
            $this->post('/seller/scenario/handoff', ['reasons' => [], 'timeout_minutes' => 10, 'timeout_notification' => true,
                'timeout_reminder' => true, 'timeout_fallback' => 'Менеджер скоро ответит, спасибо за ожидание'])->assertRedirect();
            $conversation = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'stage' => 'handoff', 'state' => '{}', 'handed_off_at' => now()->subMinutes(11), 'created_at' => now(), 'updated_at' => now()]);

            $this->artisan('seller:monitor-handoffs')->assertSuccessful();
            $this->assertDatabaseHas('seller_tasks', ['conversation_id' => $conversation, 'type' => 'reminder']);
            $this->assertDatabaseCount('outbox_events', 1);
            $event = json_decode(DB::table('outbox_events')->value('envelope'), true);
            self::assertSame('seller.conversation.handed_off', $event['type']);
            self::assertSame($org->id, $event['organization_id']);
            self::assertSame($brand->id, $event['brand_id']);
            $this->assertDatabaseHas('messages', ['conversation_id' => $conversation, 'role' => 'agent',
                'text' => 'Менеджер скоро ответит, спасибо за ожидание']);
            $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation, 'role' => 'agent', 'delivered_at' => null]);

            $this->artisan('seller:monitor-handoffs')->assertSuccessful();
            $this->assertDatabaseCount('outbox_events', 1);
            $this->assertDatabaseCount('messages', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_toggles_off_skip_notification_and_fallback(): void
    {
        Carbon::setTestNow('2026-11-07 12:00:00+00');
        try {
            Queue::fake();
            [$owner, $org, $brand] = $this->cabinet();
            $this->enable($org->id, $brand->id);
            $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
            $this->post('/seller/scenario/handoff', ['reasons' => [], 'timeout_minutes' => 10, 'timeout_notification' => false,
                'timeout_reminder' => false, 'timeout_fallback' => 'Фраза, которую не должны отправить'])->assertRedirect();
            $conversation = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'stage' => 'handoff', 'state' => '{}', 'handed_off_at' => now()->subMinutes(11), 'created_at' => now(), 'updated_at' => now()]);

            $this->artisan('seller:monitor-handoffs')->assertSuccessful();
            $this->assertDatabaseHas('seller_tasks', ['conversation_id' => $conversation, 'type' => 'reminder']);
            $this->assertDatabaseCount('outbox_events', 0);
            $this->assertDatabaseCount('messages', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_other_brand_not_affected(): void
    {
        Carbon::setTestNow('2026-11-07 12:00:00+00');
        try {
            Queue::fake();
            [$owner, $org, $brand, $other] = $this->cabinet();
            $this->enable($org->id, $brand->id);
            $this->enable($org->id, $other->id);
            $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
            $this->post('/seller/scenario/handoff', ['reasons' => [], 'timeout_minutes' => 10, 'timeout_notification' => true,
                'timeout_reminder' => true, 'timeout_fallback' => 'Фраза бренда A'])->assertRedirect();
            $conversation = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $other->id,
                'stage' => 'handoff', 'state' => '{}', 'handed_off_at' => now()->subMinutes(11), 'created_at' => now(), 'updated_at' => now()]);

            $this->artisan('seller:monitor-handoffs')->assertSuccessful();
            $this->assertDatabaseCount('outbox_events', 0);
            $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
