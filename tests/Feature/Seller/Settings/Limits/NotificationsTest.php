<?php

namespace Tests\Feature\Seller\Settings\Limits;

use App\Models\Membership;
use App\Seller\Settings\Limits\SellerNotificationRouter;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\ExternalDelivery;
use App\Shell\Notifications\NotificationProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class NotificationsTest extends TenancyTestCase
{
    private const EVENT = 'seller.conversation.handed_off';

    private function change(array $overrides = []): array
    {
        return [...['event' => self::EVENT, 'recipient' => 'manager', 'channels' => ['cabinet' => true, 'email' => false, 'telegram' => false], 'timing' => 'immediate'], ...$overrides];
    }

    public function test_owner_saves_brand_rules_without_overriding_personal_preferences(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/settings/notifications')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Seller/Settings/Limits/Notifications', false)->has('rows', 7)->where('canEdit', true)->where('digestAvailable', false));
        $this->put('/seller/settings/notifications', ['changes' => [$this->change()]])->assertRedirect('/seller/settings/notifications');
        $this->assertDatabaseHas('seller_notification_rules', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'event_key' => self::EVENT, 'recipient' => 'manager']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $owner->id, 'action' => 'seller.notification_rule.updated']);
        $this->assertDatabaseCount('notification_settings', 0);
    }

    public function test_denied_roles_and_invalid_or_unavailable_rules_do_not_write(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$editor] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($editor)->withSession(['brand_id' => $brand->id])->put('/seller/settings/notifications', ['changes' => [$this->change()]])->assertForbidden();
        $this->flushSession()->actingAs($owner)->withSession(['brand_id' => $brand->id])->put('/seller/settings/notifications', ['changes' => [$this->change(), $this->change()]])->assertSessionHasErrors('changes');
        $this->put('/seller/settings/notifications', ['changes' => [$this->change(['event' => 'seller.promise.overdue'])]])->assertSessionHasErrors('changes');
        $this->put('/seller/settings/notifications', ['changes' => [$this->change(['timing' => 'digest'])]])->assertSessionHasErrors('changes');
        $this->assertDatabaseCount('seller_notification_rules', 0);
    }

    public function test_router_enforces_rules_and_digest_is_idempotent_in_brand_timezone(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->within($org, $brand, fn () => $brand->update(['timezone' => 'Europe/Moscow']));
        [$manager, $member] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        config(['features.E6-07.defaults.digest_enabled' => true]);
        $this->put('/seller/settings/notifications', ['changes' => [$this->change(['timing' => 'digest'])]])->assertRedirect();
        $eventId = (string) Str::ulid();
        $event = new DomainEvent(['event_id' => $eventId, 'schema_version' => 1, 'type' => self::EVENT, 'occurred_at' => '2026-10-31T04:00:00Z', 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        DB::table('outbox_events')->insert(['id' => $eventId, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($event->envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31T05:00:00Z'));
        try {
            DB::transaction(function () use ($event, $member): void {
                $router = app(SellerNotificationRouter::class);
                self::assertSame('digest', $router->route($event, $member, 'cabinet'));
                self::assertSame('digest', $router->route($event, $member, 'cabinet'));
                self::assertSame('skip', $router->route($event, $member, 'email'));
            });
            $item = DB::table('seller_digest_items')->where('event_id', $eventId)->sole();
            self::assertSame($manager->id, $item->user_id);
            self::assertSame('2026-10-31 06:00', CarbonImmutable::parse($item->due_at)->utc()->format('Y-m-d H:i'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_real_outbox_processor_uses_digest_routing_before_creating_delivery(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        config(['features.E6-07.defaults.digest_enabled' => true]);
        $this->put('/seller/settings/notifications', ['changes' => [$this->change(['recipient' => 'owner', 'timing' => 'digest'])]])->assertRedirect();
        $eventId = (string) Str::ulid();
        $event = new DomainEvent(['event_id' => $eventId, 'schema_version' => 1, 'type' => self::EVENT, 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        DB::table('outbox_events')->insert(['id' => $eventId, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($event->envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        app(NotificationProcessor::class)->process($eventId);
        app(NotificationProcessor::class)->process($eventId);
        $this->assertDatabaseHas('seller_digest_items', ['event_id' => $eventId, 'user_id' => $owner->id, 'channel' => 'cabinet']);
        $this->assertDatabaseMissing('notification_deliveries', ['event_id' => $eventId]);
        self::assertSame(1, DB::table('seller_digest_items')->where('event_id', $eventId)->count());
    }

    public function test_immediate_recheck_rejects_rule_or_role_changes_without_side_effects(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $member = Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('user_id', $owner->id)->firstOrFail();
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => self::EVENT, 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        $router = app(SellerNotificationRouter::class);
        self::assertTrue($router->allowsImmediate($event, $member, 'email'));
        DB::table('seller_notification_rules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'event_key' => self::EVENT, 'recipient' => 'owner', 'cabinet' => true, 'email' => false, 'telegram' => false, 'timing' => 'immediate', 'created_at' => now(), 'updated_at' => now()]);
        self::assertFalse($router->allowsImmediate($event, $member, 'email'));
        DB::table('seller_notification_rules')->update(['email' => true, 'timing' => 'digest']);
        self::assertFalse($router->allowsImmediate($event, $member, 'email'));
        DB::table('seller_notification_rules')->update(['timing' => 'immediate', 'recipient' => 'admin']);
        self::assertFalse($router->allowsImmediate($event, $member, 'email'));
        $member->status = 'disabled';
        self::assertFalse($router->allowsImmediate($event, $member, 'email'));
        $this->assertDatabaseCount('seller_digest_items', 0);
    }

    public function test_real_external_delivery_does_not_send_after_rule_changes(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $eventId = (string) Str::ulid();
        $event = new DomainEvent(['event_id' => $eventId, 'schema_version' => 1, 'type' => self::EVENT, 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        DB::table('outbox_events')->insert(['id' => $eventId, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($event->envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        DB::table('notification_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'event_key' => self::EVENT, 'channel' => 'telegram', 'enabled' => true, 'destination' => Crypt::encryptString('12345'), 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::ulid();
        DB::table('notification_deliveries')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'event_id' => $eventId, 'user_id' => $owner->id, 'channel' => 'telegram', 'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_notification_rules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'event_key' => self::EVENT, 'recipient' => 'owner', 'cabinet' => true, 'email' => true, 'telegram' => false, 'timing' => 'immediate', 'created_at' => now(), 'updated_at' => now()]);
        config(['features.E0-04.defaults.external_delivery' => true, 'features.E0-04.defaults.telegram_bot_token' => 'test-only']);
        Http::fake();
        app(ExternalDelivery::class)->send($id);
        Http::assertNothingSent();
        $this->assertDatabaseHas('notification_deliveries', ['id' => $id, 'status' => 'failed']);
    }

    public function test_daylight_saving_gap_and_repeated_hour_produce_one_future_slot(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->within($org, $brand, fn () => $brand->update(['timezone' => 'America/New_York']));
        config(['features.E6-07.defaults.digest_enabled' => true]);
        $member = Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('user_id', $owner->id)->firstOrFail();
        DB::table('seller_notification_rules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'event_key' => self::EVENT, 'recipient' => 'owner', 'cabinet' => true, 'email' => false, 'telegram' => false, 'timing' => 'digest', 'created_at' => now(), 'updated_at' => now()]);
        $cases = [
            ['2026-03-08T05:00:00Z', '02:30', '2026-03-08 07:30'],
            ['2026-11-01T04:00:00Z', '01:30', '2026-11-01 05:30'],
        ];
        try {
            foreach ($cases as [$now, $time, $expected]) {
                CarbonImmutable::setTestNow($now);
                DB::table('seller_digest_preferences')->updateOrInsert(['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id], ['id' => (string) Str::ulid(), 'digest_time' => $time, 'created_at' => now(), 'updated_at' => now()]);
                $id = (string) Str::ulid();
                $event = new DomainEvent(['event_id' => $id, 'schema_version' => 1, 'type' => self::EVENT, 'occurred_at' => $now, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
                DB::table('outbox_events')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($event->envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                DB::transaction(fn () => app(SellerNotificationRouter::class)->route($event, $member, 'cabinet'));
                $due = DB::table('seller_digest_items')->where('event_id', $id)->value('due_at');
                self::assertSame($expected, CarbonImmutable::parse($due)->utc()->format('Y-m-d H:i'));
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
