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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FakeTimingErrorsE110JTest extends TenancyTestCase
{
    public function test_failure_is_isolated_and_explicit_retry_recovers(): void
    {
        Http::preventStrayRequests();
        config(['runtime.notification_driver' => 'fake']);
        [$user, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            $context = new TenantContext($org->id, $item->id, $user->id, $membership->id);
            app(TenantContextRunner::class)->run($context, fn () => app(ConfigureNotification::class)->execute($context, 'vision.measurement.completed', 'email', true));
            $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'vision.measurement.completed', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'actor_id' => null, 'aggregate' => ['type' => 'measurement', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['measurement_id' => (string) Str::ulid()]]);
            DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
            app(NotificationProcessor::class)->process($event->envelope['event_id']);
        }
        $transport = $this->mock(FakeTimingTransport::class);
        $transport->shouldReceive('send')->once()->withArgs(fn ($o, $b) => $b === $brand->id)->andThrow(new RuntimeException('secret error'));
        $transport->shouldReceive('send')->once()->withArgs(fn ($o, $b) => $b === $other->id);
        app(FakeTimedDelivery::class)->drain();
        $this->assertDatabaseHas('notification_deliveries', ['brand_id' => $brand->id, 'channel' => 'email', 'status' => 'failed', 'last_error' => 'delivery_failed']);
        $this->assertDatabaseHas('notification_deliveries', ['brand_id' => $other->id, 'channel' => 'email', 'status' => 'sent']);
        $transport->shouldReceive('send')->once()->withArgs(fn ($o, $b) => $b === $brand->id);
        app(FakeTimedDelivery::class)->drain(true);
        $this->assertDatabaseHas('notification_deliveries', ['brand_id' => $brand->id, 'channel' => 'email', 'status' => 'sent', 'last_error' => null]);
        Http::assertNothingSent();
    }
}
