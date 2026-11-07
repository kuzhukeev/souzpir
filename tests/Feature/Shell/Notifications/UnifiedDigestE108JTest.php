<?php

namespace Tests\Feature\Shell\Notifications;

use App\Seller\Settings\Limits\DigestService;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class UnifiedDigestE108JTest extends LimitsTestCase
{
    public function test_timing_rejects_anonymous_and_invalid_requests(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->putJson('/profile/notifications/timing', ['digest_time' => '09:00'])->assertUnauthorized();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/profile/notifications')->assertOk()->viewData('page')['props']['notificationSettings']['timingRevision'];
        $this->withHeader('X-Brand-Revision', $revision)->putJson('/profile/notifications/timing', ['digest_time' => '9:00'])->assertUnprocessable();
        $this->putJson('/profile/notifications/timing', ['quiet_start' => '24:00', 'quiet_end' => '06:00'])->assertUnprocessable();
    }

    public static function legacyDeliveryStates(): array
    {
        return ['new delivery' => [false], 'existing delivery' => [true]];
    }

    #[DataProvider('legacyDeliveryStates')]
    public function test_seller_and_other_events_share_fake_digest_after_quiet_hours(bool $existing): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake();
        config(['runtime.notification_driver' => 'fake', 'features.E6-07.defaults.digest_enabled' => true, 'features.E0-04.defaults.external_delivery' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 07:00 UTC'));
        [$user, $org, $brand, $other, $membership] = $this->cabinet();
        [$viewer, $viewerMembership] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brands')->where('id', $item->id)->update(['timezone' => 'UTC']);
            DB::table('seller_notification_rules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'event_key' => 'seller.conversation.handed_off', 'recipient' => 'owner', 'cabinet' => true, 'email' => true, 'telegram' => false, 'timing' => 'digest', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('profile_notification_timing_drafts')->insert(['organization_id' => $org->id, 'brand_id' => $item->id, 'user_id' => $user->id, 'quiet_start' => '09:00', 'quiet_end' => '10:00', 'digest_time' => null, 'created_at' => now(), 'updated_at' => now()]);
            $context = new TenantContext($org->id, $item->id, $user->id, $membership->id);
            app(TenantContextRunner::class)->run($context, function () use ($context): void {
                foreach (['seller.conversation.handed_off', 'vision.measurement.completed'] as $type) {
                    app(ConfigureNotification::class)->execute($context, $type, 'email', true);
                }
            });
        }
        $viewerContext = new TenantContext($org->id, $brand->id, $viewer->id, $viewerMembership->id);
        app(TenantContextRunner::class)->run($viewerContext, fn () => app(ConfigureNotification::class)->execute($viewerContext, 'seller.conversation.handed_off', 'email', true));
        foreach ([$brand, $other] as $item) {
            foreach (['seller.conversation.handed_off', 'vision.measurement.completed'] as $type) {
                $data = $type === 'seller.conversation.handed_off' ? ['reason' => 'want_human', 'task_id' => (string) Str::ulid()] : ['measurement_id' => (string) Str::ulid()];
                $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => $type, 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => $data]);
                DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
                app(NotificationProcessor::class)->process($event->envelope['event_id']);
                if ($item->id === $brand->id && $type === 'seller.conversation.handed_off') {
                    if (! $existing) {
                        DB::table('notification_deliveries')->where('event_id', $event->envelope['event_id'])->where('channel', 'email')->delete();
                    }
                    $batch = (string) Str::ulid();
                    $slot = ['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $user->id, 'channel' => 'email', 'slot_date' => '2026-11-05', 'due_at' => '2026-11-05 09:00:00+00'];
                    DB::table('seller_digest_batches')->insert([...$slot, 'id' => $batch, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('seller_digest_items')->insert([...$slot, 'id' => (string) Str::ulid(), 'batch_id' => $batch, 'event_id' => $event->envelope['event_id'], 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        $transport = $this->mock(FakeTimingTransport::class);
        $transport->shouldReceive('send')->twice()->withArgs(fn ($o, $b, $u, $c, $titles) => $o === $org->id && in_array($b, [$brand->id, $other->id], true) && $u === $user->id && $c === 'email' && count($titles) === 2);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00 UTC'));
        app(DigestService::class)->run();
        app(FakeTimedDelivery::class)->drain();
        $this->assertSame(4, DB::table('notification_deliveries')->where('channel', 'email')->where('status', 'pending')->count());
        $this->assertSame(0, DB::table('notification_deliveries')->where('user_id', $viewer->id)->count());
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00 UTC'));
        app(FakeTimedDelivery::class)->drain();
        $this->assertSame(4, DB::table('notification_deliveries')->where('channel', 'email')->where('status', 'sent')->count());
        app(FakeTimedDelivery::class)->drain();
        $this->assertSame(1, DB::table('seller_digest_batches')->where('last_error', 'moved_to_fake_digest')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
