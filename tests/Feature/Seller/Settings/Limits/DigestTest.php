<?php

namespace Tests\Feature\Seller\Settings\Limits;

use App\Models\Membership;
use App\Seller\Settings\Limits\DigestService;
use App\Seller\Settings\Limits\DigestTransport;
use App\Seller\Settings\Limits\SellerNotificationRouter;
use App\Seller\Settings\Limits\TestDeliveryService;
use App\Shell\Notifications\DomainEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

final class DigestTest extends LimitsTestCase
{
    private const EVENT = 'seller.conversation.handed_off';

    private function queued(string $channel = 'cabinet'): array
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->within($org, $brand, fn () => $brand->update(['timezone' => 'Europe/Moscow']));
        $member = DB::table('memberships')->where('organization_id', $org->id)->where('user_id', $owner->id)->first();
        $membership = Membership::withoutGlobalScopes()->findOrFail($member->id);
        DB::table('seller_notification_rules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'event_key' => self::EVENT, 'recipient' => 'owner', 'cabinet' => true, 'email' => true, 'telegram' => false, 'timing' => 'digest', 'created_at' => now(), 'updated_at' => now()]);
        if ($channel === 'email') {
            DB::table('notification_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'event_key' => self::EVENT, 'channel' => 'email', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = (string) Str::ulid();
        $event = new DomainEvent(['event_id' => $id, 'schema_version' => 1, 'type' => self::EVENT, 'occurred_at' => '2026-10-31T04:00:00Z', 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        DB::table('outbox_events')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($event->envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        config(['features.E6-07.defaults.digest_enabled' => true]);
        Carbon::setTestNow('2026-10-31T05:00:00Z');
        CarbonImmutable::setTestNow('2026-10-31T05:00:00Z');
        DB::transaction(fn () => app(SellerNotificationRouter::class)->route($event, $membership, $channel));
        Carbon::setTestNow('2026-10-31T06:01:00Z');
        CarbonImmutable::setTestNow('2026-10-31T06:01:00Z');
        config(['features.E6-07.defaults.digest_enabled' => true]);

        return [$owner, $org, $brand];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_cabinet_digest_is_idempotent_visible_only_to_recipient_and_brand(): void
    {
        [$owner, $org, $brand] = $this->queued();
        $service = app(DigestService::class);
        $service->run();
        $service->run();
        $this->assertDatabaseCount('seller_digest_batches', 1);
        $this->assertDatabaseHas('seller_digest_batches', ['user_id' => $owner->id, 'channel' => 'cabinet', 'status' => 'sent']);
        $this->assertDatabaseCount('seller_digest_cabinet_notifications', 1);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings/notifications/digests')->assertOk()->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.titles.0', 'Диалог ждёт менеджера'));
        [$other] = $this->member($org, 'member', [$brand->id]);
        $this->flushSession()->actingAs($other)->withSession(['brand_id' => $brand->id])->get('/seller/settings/notifications/digests')->assertOk()->assertInertia(fn (Assert $p) => $p->has('items', 0));
    }

    public function test_revoked_channel_or_access_cancels_without_sending(): void
    {
        [$owner, $org, $brand] = $this->queued();
        DB::table('seller_notification_rules')->where('organization_id', $org->id)->update(['cabinet' => false]);
        app(DigestService::class)->run();
        $this->assertDatabaseHas('seller_digest_batches', ['user_id' => $owner->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('seller_digest_cabinet_notifications', 0);
    }

    public function test_disabled_module_cancels_queued_digest_before_delivery(): void
    {
        [$owner, $org, $brand] = $this->queued();
        DB::table('brand_modules')->where('brand_id', $brand->id)->where('module', 'seller')->update(['enabled' => false]);
        app(DigestService::class)->run();
        $this->assertDatabaseHas('seller_digest_batches', ['user_id' => $owner->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('seller_digest_cabinet_notifications', 0);
    }

    public function test_personal_opt_out_cancels_queued_digest_before_delivery(): void
    {
        [$owner, $org, $brand] = $this->queued();
        DB::table('notification_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'event_key' => self::EVENT, 'channel' => 'cabinet', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        app(DigestService::class)->run();
        $this->assertDatabaseHas('seller_digest_batches', ['user_id' => $owner->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('seller_digest_cabinet_notifications', 0);
    }

    public function test_disabled_module_cancels_queued_test_before_delivery(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        Queue::fake();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/settings/notifications/test', ['event' => self::EVENT, 'channel' => 'cabinet', 'key' => (string) Str::uuid()])->assertRedirect();
        DB::table('brand_modules')->where('brand_id', $brand->id)->where('module', 'seller')->update(['enabled' => false]);
        app(TestDeliveryService::class)->deliver(DB::table('seller_test_deliveries')->value('id'));
        $this->assertDatabaseHas('seller_test_deliveries', ['user_id' => $owner->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('seller_test_cabinet_notifications', 0);
    }

    public function test_delivered_event_is_hidden_if_recipient_role_loses_permission(): void
    {
        [$owner, $org, $brand] = $this->queued();
        app(DigestService::class)->run();
        // Simulate an already delivered event whose sensitivity requires owner/admin.
        DB::table('seller_digest_cabinet_notifications')->update([
            'event_keys' => json_encode(['seller.supervisor.completed']),
            'titles' => json_encode(['Доклад готов'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings/notifications/digests')->assertInertia(fn (Assert $p) => $p->has('items', 1));
        DB::table('memberships')->where('organization_id', $org->id)->where('user_id', $owner->id)->update(['role' => 'viewer', 'brand_ids' => json_encode([$brand->id])]);
        $this->get('/seller/settings/notifications/digests')->assertOk()->assertInertia(fn (Assert $p) => $p->has('items', 0));
    }

    public function test_external_delivery_is_refused_inside_an_existing_transaction(): void
    {
        $this->queued('email');
        config(['features.E0-04.defaults.external_delivery' => true, 'mail.default' => 'smtp']);
        $fake = new class extends DigestTransport
        {
            public int $calls = 0;

            public function send(string $channel, string $destination, array $titles): void
            {
                $this->calls++;
                throw new \RuntimeException('network result unknown');
            }
        };
        $this->app->instance(DigestTransport::class, $fake);
        $service = app(DigestService::class);
        $this->expectException(\LogicException::class);
        try {
            $service->run();
        } finally {
            self::assertSame(0, $fake->calls);
        }
    }

    public function test_test_delivery_is_durable_idempotent_and_visible_only_to_current_user(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        Queue::fake();
        $body = ['event' => self::EVENT, 'channel' => 'cabinet', 'key' => (string) Str::uuid()];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/settings/notifications/test', $body)->assertRedirect();
        $this->post('/seller/settings/notifications/test', $body)->assertRedirect();
        $this->assertDatabaseCount('seller_test_deliveries', 1);
        $id = DB::table('seller_test_deliveries')->value('id');
        $service = app(TestDeliveryService::class);
        $service->deliver($id);
        $service->deliver($id);
        $this->assertDatabaseHas('seller_test_deliveries', ['id' => $id, 'status' => 'sent']);
        $this->assertDatabaseCount('seller_test_cabinet_notifications', 1);
        $this->get('/seller/settings/notifications/digests')->assertOk()->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.titles.0', 'Тест уведомлений продавца'));
        [$other] = $this->member($org, 'member', [$brand->id]);
        $this->flushSession()->actingAs($other)->withSession(['brand_id' => $brand->id])->get('/seller/settings/notifications/digests')->assertOk()->assertInertia(fn (Assert $p) => $p->has('items', 0));
    }

    public function test_test_delivery_rechecks_revoked_personal_channel_before_any_transport(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        Queue::fake();
        $body = ['event' => self::EVENT, 'channel' => 'cabinet', 'key' => (string) Str::uuid()];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/settings/notifications/test', $body)->assertRedirect();
        DB::table('notification_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'event_key' => self::EVENT, 'channel' => 'cabinet', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        app(TestDeliveryService::class)->deliver(DB::table('seller_test_deliveries')->value('id'));
        $this->assertDatabaseHas('seller_test_deliveries', ['user_id' => $owner->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('seller_test_cabinet_notifications', 0);
    }
}
