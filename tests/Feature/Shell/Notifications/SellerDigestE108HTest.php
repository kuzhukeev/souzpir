<?php

namespace Tests\Feature\Shell\Notifications;

use App\Seller\Settings\Limits\DigestService;
use App\Seller\Settings\Limits\SellerNotificationRouter;
use App\Shell\Notifications\ConfigureNotification;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\FakeTimedDelivery;
use App\Shell\Notifications\FakeTimingTransport;
use App\Shell\Notifications\NotificationProcessor;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class SellerDigestE108HTest extends LimitsTestCase
{
    public function test_personal_settings_validate_times_and_require_authentication(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->putJson('/profile/notifications/timing', ['digest_time' => '09:00'])->assertUnauthorized();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/profile/notifications')->assertOk()->viewData('page')['props']['notificationSettings']['timingRevision'];
        $this->withHeader('X-Brand-Revision', $revision)->putJson('/profile/notifications/timing', ['quiet_start' => '24:00', 'quiet_end' => '06:00'])->assertUnprocessable();
        $this->putJson('/profile/notifications/timing', ['digest_time' => '9:00'])->assertUnprocessable();
        $this->assertDatabaseCount('profile_notification_timing_drafts', 0);
    }

    public function test_seller_digest_respects_personal_time_quiet_hours_and_brand(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake();
        config(['runtime.notification_driver' => 'fake', 'features.E6-07.defaults.digest_enabled' => true, 'features.E0-04.defaults.external_delivery' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 07:00 UTC'));
        [$user, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brands')->where('id', $item->id)->update(['timezone' => 'UTC']);
            DB::table('seller_notification_rules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'event_key' => 'seller.conversation.handed_off', 'recipient' => 'owner', 'cabinet' => true, 'email' => true, 'telegram' => false, 'timing' => 'digest', 'created_at' => now(), 'updated_at' => now()]);
            $context = new TenantContext($org->id, $item->id, $user->id, $membership->id);
            app(TenantContextRunner::class)->run($context, fn () => app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'email', true));
        }
        DB::table('profile_notification_timing_drafts')->insert(['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $user->id, 'quiet_start' => '09:00', 'quiet_end' => '10:00', 'digest_time' => '09:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_digest_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $user->id, 'digest_time' => '11:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_digest_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id, 'user_id' => $user->id, 'digest_time' => '08:00', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$brand, $other] as $item) {
            $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
            DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
            app(NotificationProcessor::class)->process($event->envelope['event_id']);
            self::assertSame('immediate', DB::transaction(fn () => app(SellerNotificationRouter::class)->route($event, $membership, 'cabinet')));
        }
        self::assertSame(1, DB::table('notification_deliveries')->where('brand_id', $brand->id)->where('channel', 'email')->count());
        self::assertSame(1, DB::table('notification_deliveries')->where('brand_id', $other->id)->where('channel', 'email')->count());
        $transport = $this->mock(FakeTimingTransport::class);
        $transport->shouldReceive('send')->once()->withArgs(fn ($o, $b, $u, $c, $titles) => $b === $other->id && count($titles) === 1);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00 UTC'));
        app(DigestService::class)->run();
        app(FakeTimedDelivery::class)->drain();
        self::assertSame('pending', DB::table('notification_deliveries')->where('brand_id', $brand->id)->where('channel', 'email')->value('status'));
        $transport->shouldReceive('send')->once()->withArgs(fn ($o, $b, $u, $c, $titles) => $b === $brand->id && count($titles) === 1);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00 UTC'));
        app(FakeTimedDelivery::class)->drain();
        self::assertSame('sent', DB::table('notification_deliveries')->where('brand_id', $brand->id)->where('channel', 'email')->value('status'));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
