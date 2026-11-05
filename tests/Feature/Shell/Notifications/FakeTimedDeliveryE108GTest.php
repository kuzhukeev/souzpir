<?php

namespace Tests\Feature\Shell\Notifications;

use App\Shell\Notifications\ConfigureNotification;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\FakeTimedDelivery;
use App\Shell\Notifications\FakeTimingTransport;
use App\Shell\Notifications\Jobs\DeliverNotification;
use App\Shell\Notifications\NotificationProcessor;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FakeTimedDeliveryE108GTest extends TenancyTestCase
{
    public function test_quiet_hours_and_daily_slot_use_brand_timezone(): void
    {
        $timing = new FakeTimedDelivery;
        $this->assertSame('2026-11-05 05:00', $timing->dueAt(CarbonImmutable::parse('2026-11-04 21:30 UTC'), 'Europe/Moscow', '22:00', '08:00', null)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-05 06:00', $timing->dueAt(CarbonImmutable::parse('2026-11-05 04:00 UTC'), 'Europe/Moscow', '22:00', '08:00', '09:00')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-06 06:00', $timing->dueAt(CarbonImmutable::parse('2026-11-05 06:01 UTC'), 'Europe/Moscow', null, null, '09:00')->format('Y-m-d H:i'));
    }

    public function test_fake_digest_groups_per_brand_and_channel_without_live_sends(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake();
        config(['runtime.notification_driver' => 'fake', 'features.E0-04.defaults.external_delivery' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 07:00 UTC'));
        [$user, $org, $brand, $other, $membership] = $this->cabinet();
        [$viewer, $viewerMembership] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$brand, $other] as $item) {
            DB::table('brands')->where('id', $item->id)->update(['timezone' => 'UTC']);
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $context = new TenantContext($org->id, $item->id, $user->id, $membership->id);
            app(TenantContextRunner::class)->run($context, function () use ($context): void {
                app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'email', true);
                app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'telegram', true, '123456');
            });
        }
        $viewerContext = new TenantContext($org->id, $brand->id, $viewer->id, $viewerMembership->id);
        app(TenantContextRunner::class)->run($viewerContext, fn () => app(ConfigureNotification::class)->execute($viewerContext, 'seller.conversation.handed_off', 'email', true));
        DB::table('profile_notification_timing_drafts')->insert(['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $user->id, 'quiet_start' => '22:00', 'quiet_end' => '08:00', 'digest_time' => '09:00', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$brand, $brand, $other] as $item) {
            $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
            DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
            app(NotificationProcessor::class)->process($event->envelope['event_id']);
        }
        Queue::assertNotPushed(DeliverNotification::class);
        $transport = $this->mock(FakeTimingTransport::class);
        $transport->shouldReceive('send')->twice()->withArgs(fn ($organization, $brandId, $userId, $channel, $titles) => $brandId === $other->id && count($titles) === 1);
        app(FakeTimedDelivery::class)->drain();
        $this->assertSame(4, DB::table('notification_deliveries')->where('brand_id', $brand->id)->where('status', 'pending')->count());
        $this->assertSame(0, DB::table('notification_deliveries')->where('user_id', $viewer->id)->count());
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00 UTC'));
        $transport->shouldReceive('send')->twice()->withArgs(fn ($organization, $brandId, $userId, $channel, $titles) => $brandId === $brand->id && count($titles) === 2);
        app(FakeTimedDelivery::class)->drain();
        $this->assertSame(4, DB::table('notification_deliveries')->where('brand_id', $brand->id)->where('channel', '!=', 'cabinet')->where('status', 'sent')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
