<?php

namespace Tests\Feature\Shell\Notifications;

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

final class UnifiedDigestE108ITest extends LimitsTestCase
{
    public function test_seller_and_other_events_share_fake_digest_after_quiet_hours(): void
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
            DB::table('profile_notification_timing_drafts')->insert(['organization_id' => $org->id, 'brand_id' => $item->id, 'user_id' => $user->id, 'quiet_start' => '09:00', 'quiet_end' => '10:00', 'digest_time' => '09:00', 'created_at' => now(), 'updated_at' => now()]);
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
            }
        }
        $transport = $this->mock(FakeTimingTransport::class);
        $transport->shouldReceive('send')->twice()->withArgs(fn ($o, $b, $u, $c, $titles) => $o === $org->id && in_array($b, [$brand->id, $other->id], true) && $u === $user->id && $c === 'email' && count($titles) === 2);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00 UTC'));
        app(FakeTimedDelivery::class)->drain();
        $this->assertSame(4, DB::table('notification_deliveries')->where('channel', 'email')->where('status', 'pending')->count());
        $this->assertSame(0, DB::table('notification_deliveries')->where('user_id', $viewer->id)->count());
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00 UTC'));
        app(FakeTimedDelivery::class)->drain();
        $this->assertSame(4, DB::table('notification_deliveries')->where('channel', 'email')->where('status', 'sent')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
