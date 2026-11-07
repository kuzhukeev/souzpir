<?php

namespace Tests\Feature\Seller\Settings\Limits;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Settings\Limits\DigestService;
use App\Seller\Settings\Limits\SellerNotificationRouter;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\NotificationDeferred;
use App\Shell\Notifications\NotificationProcessor;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

final class PersonalDigestTimeTest extends LimitsTestCase
{
    private const HANDOFF = 'seller.conversation.handed_off';

    private const UNKNOWN = 'seller.unknown_question.logged';

    private function rule(Organization $org, Brand $brand, string $event): void
    {
        DB::table('seller_notification_rules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'event_key' => $event, 'recipient' => 'all', 'cabinet' => true, 'email' => false, 'telegram' => false, 'timing' => 'digest', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function event(Organization $org, Brand $brand, string $event): DomainEvent
    {
        $id = (string) Str::ulid();
        $data = $event === self::HANDOFF ? ['reason' => 'want_human', 'task_id' => (string) Str::ulid()] : ['task_id' => (string) Str::ulid()];
        $outbox = new DomainEvent(['event_id' => $id, 'schema_version' => 1, 'type' => $event, 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => $data]);
        DB::table('outbox_events')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($outbox->envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);

        return $outbox;
    }

    private function route(DomainEvent $event, User $user): void
    {
        $member = Membership::withoutGlobalScopes()->where('organization_id', $event->envelope['organization_id'])->where('user_id', $user->id)->firstOrFail();
        DB::transaction(fn () => app(SellerNotificationRouter::class)->route($event, $member, 'cabinet'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        // This suite exercises the cabinet digest timing path, which the fake
        // driver's "cabinet is always immediate" shortcut would bypass.
        config(['runtime.notification_driver' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_two_events_group_per_recipient_and_recipients_choose_different_times(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        $this->within($org, $brand, fn () => $brand->update(['timezone' => 'Europe/Moscow']));
        $this->rule($org, $brand, self::HANDOFF);
        $this->rule($org, $brand, self::UNKNOWN);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->put('/seller/settings/notifications/digests/time', ['digest_time' => '11:30'])->assertRedirect();
        $this->flushSession()->actingAs($admin)->withSession(['brand_id' => $brand->id])->put('/seller/settings/notifications/digests/time', ['digest_time' => '13:45'])->assertRedirect();
        $this->assertDatabaseHas('seller_digest_preferences', ['user_id' => $owner->id, 'digest_time' => '11:30']);
        $this->assertDatabaseHas('seller_digest_preferences', ['user_id' => $admin->id, 'digest_time' => '13:45']);
        config(['features.E6-07.defaults.digest_enabled' => true]);
        CarbonImmutable::setTestNow('2026-10-31T05:00:00Z');
        foreach ([self::HANDOFF, self::UNKNOWN] as $type) {
            $event = $this->event($org, $brand, $type);
            $this->route($event, $owner);
            $this->route($event, $admin);
        }
        foreach ([[$owner, '08:30'], [$admin, '10:45']] as [$user, $expected]) {
            $items = DB::table('seller_digest_items')->where('user_id', $user->id)->get();
            self::assertCount(2, $items);
            foreach ($items as $item) {
                self::assertSame($expected, CarbonImmutable::parse($item->due_at)->utc()->format('H:i'));
            }
        }
        Carbon::setTestNow('2026-10-31T11:00:00Z');
        app(DigestService::class)->assemble();
        self::assertSame(2, DB::table('seller_digest_batches')->count());
        foreach (DB::table('seller_digest_batches')->get() as $batch) {
            self::assertSame(2, DB::table('seller_digest_items')->where('batch_id', $batch->id)->count());
        }
    }

    public function test_time_change_keeps_pending_slot_and_sent_slot_cannot_repeat_that_day(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->within($org, $brand, fn () => $brand->update(['timezone' => 'Europe/Moscow']));
        $this->rule($org, $brand, self::HANDOFF);
        $this->rule($org, $brand, self::UNKNOWN);
        config(['features.E6-07.defaults.digest_enabled' => true]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->put('/seller/settings/notifications/digests/time', ['digest_time' => '11:30'])->assertRedirect();
        CarbonImmutable::setTestNow('2026-10-31T05:00:00Z');
        $this->route($this->event($org, $brand, self::HANDOFF), $owner);
        $this->put('/seller/settings/notifications/digests/time', ['digest_time' => '13:45'])->assertRedirect();
        $this->route($this->event($org, $brand, self::UNKNOWN), $owner);
        self::assertSame(1, DB::table('seller_digest_items')->distinct()->count('due_at'));
        self::assertSame('08:30', CarbonImmutable::parse(DB::table('seller_digest_items')->value('due_at'))->utc()->format('H:i'));
        Carbon::setTestNow('2026-10-31T08:31:00Z');
        CarbonImmutable::setTestNow('2026-10-31T08:31:00Z');
        app(DigestService::class)->run();
        $this->assertDatabaseHas('seller_digest_batches', ['user_id' => $owner->id, 'slot_date' => '2026-10-31', 'status' => 'sent']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->put('/seller/settings/notifications/digests/time', ['digest_time' => '17:15'])->assertRedirect('/seller/settings/notifications/digests');
        $third = $this->event($org, $brand, self::HANDOFF);
        $this->route($third, $owner);
        $item = DB::table('seller_digest_items')->where('event_id', $third->envelope['event_id'])->sole();
        self::assertSame('2026-11-01', $item->slot_date);
        self::assertSame('14:15', CarbonImmutable::parse($item->due_at)->utc()->format('H:i'));
    }

    public function test_disabled_digest_rolls_back_outbox_and_reprocesses_after_enable(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->rule($org, $brand, self::HANDOFF);
        $event = $this->event($org, $brand, self::HANDOFF);
        config(['features.E6-07.defaults.digest_enabled' => false]);
        try {
            app(NotificationProcessor::class)->process($event->envelope['event_id']);
            self::fail('Disabled digest must defer the outbox event.');
        } catch (NotificationDeferred $e) {
            self::assertSame('digest_disabled', $e->reason);
        }
        $this->assertDatabaseHas('outbox_events', ['id' => $event->envelope['event_id'], 'processed_at' => null]);
        $this->assertDatabaseCount('seller_digest_items', 0);
        $this->assertDatabaseMissing('consumed_events', ['event_id' => $event->envelope['event_id']]);
        $this->assertDatabaseMissing('notification_deliveries', ['event_id' => $event->envelope['event_id']]);
        $this->assertDatabaseHas('seller_notification_rules', ['event_key' => self::HANDOFF, 'timing' => 'digest']);
        config(['features.E6-07.defaults.digest_enabled' => true]);
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $this->assertDatabaseHas('seller_digest_items', ['event_id' => $event->envelope['event_id'], 'user_id' => $owner->id]);
        self::assertNotNull(DB::table('outbox_events')->where('id', $event->envelope['event_id'])->value('processed_at'));
    }

    public function test_viewer_has_no_time_setting_and_cannot_write_another_user(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/settings/notifications/digests')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canSetTime', false));
        $this->put('/seller/settings/notifications/digests/time', ['digest_time' => '08:00', 'user_id' => $owner->id])->assertForbidden();
        $this->assertDatabaseCount('seller_digest_preferences', 0);
    }

    public function test_member_changes_only_own_personal_time(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id])->get('/seller/settings/notifications/digests')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canSetTime', true)->where('canReadSettings', false));
        $this->actingAs($manager)->withSession(['brand_id' => $brand->id])->put('/seller/settings/notifications/digests/time', ['digest_time' => '18:20', 'user_id' => $owner->id])->assertRedirect();
        $this->assertDatabaseHas('seller_digest_preferences', ['user_id' => $manager->id, 'brand_id' => $brand->id, 'digest_time' => '18:20']);
        $this->assertDatabaseMissing('seller_digest_preferences', ['user_id' => $owner->id]);
        $this->put('/seller/settings/notifications/digests/time', ['digest_time' => '24:01'])->assertSessionHasErrors('digest_time');
    }

    public function test_repeated_dst_hour_never_creates_second_digest_same_local_date(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->within($org, $brand, fn () => $brand->update(['timezone' => 'America/New_York']));
        $this->rule($org, $brand, self::HANDOFF);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->put('/seller/settings/notifications/digests/time', ['digest_time' => '01:30'])->assertRedirect();
        config(['features.E6-07.defaults.digest_enabled' => true]);
        Carbon::setTestNow('2026-11-01T05:15:00Z');
        CarbonImmutable::setTestNow('2026-11-01T05:15:00Z');
        $first = $this->event($org, $brand, self::HANDOFF);
        $this->route($first, $owner);
        self::assertSame('2026-11-01 05:30', CarbonImmutable::parse(DB::table('seller_digest_items')->where('event_id', $first->envelope['event_id'])->value('due_at'))->utc()->format('Y-m-d H:i'));
        Carbon::setTestNow('2026-11-01T05:31:00Z');
        CarbonImmutable::setTestNow('2026-11-01T05:31:00Z');
        app(DigestService::class)->run();
        $this->assertDatabaseHas('seller_digest_batches', ['user_id' => $owner->id, 'slot_date' => '2026-11-01', 'status' => 'sent']);
        Carbon::setTestNow('2026-11-01T06:15:00Z');
        CarbonImmutable::setTestNow('2026-11-01T06:15:00Z');
        $second = $this->event($org, $brand, self::HANDOFF);
        $this->route($second, $owner);
        $item = DB::table('seller_digest_items')->where('event_id', $second->envelope['event_id'])->sole();
        self::assertSame('2026-11-02', $item->slot_date);
        self::assertSame('2026-11-02 06:30', CarbonImmutable::parse($item->due_at)->utc()->format('Y-m-d H:i'));
    }

    public function test_first_occurrence_is_selected_even_if_event_arrives_in_second_dst_hour(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$other] = $this->member($org, 'admin', [$brand->id]);
        $this->within($org, $brand, fn () => $brand->update(['timezone' => 'America/New_York']));
        $this->rule($org, $brand, self::HANDOFF);
        foreach ([$owner, $other] as $user) {
            DB::table('seller_digest_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $user->id, 'digest_time' => '01:30', 'created_at' => now(), 'updated_at' => now()]);
        }
        config(['features.E6-07.defaults.digest_enabled' => true]);
        CarbonImmutable::setTestNow('2026-11-01T05:15:00Z');
        $first = $this->event($org, $brand, self::HANDOFF);
        $this->route($first, $owner);
        CarbonImmutable::setTestNow('2026-11-01T06:15:00Z');
        $second = $this->event($org, $brand, self::HANDOFF);
        $this->route($second, $other);
        $early = DB::table('seller_digest_items')->where('event_id', $first->envelope['event_id'])->value('due_at');
        $late = DB::table('seller_digest_items')->where('event_id', $second->envelope['event_id'])->value('due_at');
        self::assertSame('2026-11-01 05:30', CarbonImmutable::parse($early)->utc()->format('Y-m-d H:i'));
        self::assertSame('2026-11-02 06:30', CarbonImmutable::parse($late)->utc()->format('Y-m-d H:i'));
    }

    public function test_existing_slot_survives_brand_timezone_change(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->within($org, $brand, fn () => $brand->update(['timezone' => 'Europe/Moscow']));
        $this->rule($org, $brand, self::HANDOFF);
        $this->rule($org, $brand, self::UNKNOWN);
        config(['features.E6-07.defaults.digest_enabled' => true]);
        CarbonImmutable::setTestNow('2026-10-31T05:00:00Z');
        $first = $this->event($org, $brand, self::HANDOFF);
        $this->route($first, $owner);
        $this->within($org, $brand, fn () => $brand->update(['timezone' => 'Asia/Tokyo']));
        $second = $this->event($org, $brand, self::UNKNOWN);
        $this->route($second, $owner);
        $items = DB::table('seller_digest_items')->whereIn('event_id', [$first->envelope['event_id'], $second->envelope['event_id']])->get();
        self::assertCount(2, $items);
        self::assertSame($items[0]->slot_date, $items[1]->slot_date);
        self::assertSame($items[0]->due_at, $items[1]->due_at);
    }
}
