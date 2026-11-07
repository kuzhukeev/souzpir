<?php

namespace Tests\Feature\Shell\Integrations\Webhooks;

use App\Shell\Integrations\Webhooks\DeliverFakeWebhook;
use App\Shell\Integrations\Webhooks\FakeWebhookTransport;
use App\Shell\Integrations\Webhooks\ProcessFakeWebhookEvent;
use App\Shell\Integrations\Webhooks\WebhookOutbox;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WebhookSnapshotE110JTest extends TenancyTestCase
{
    public function test_fake_event_uses_subscriptions_from_transaction_and_never_crosses_brands(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['runtime.notification_driver' => 'fake']);
        [, $org, $brand, $other] = $this->cabinet();
        $hook = (string) Str::ulid();
        $otherHook = (string) Str::ulid();
        foreach ([[$hook, $brand], [$otherHook, $other]] as [$id, $item]) {
            DB::table('webhooks')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'url' => Crypt::encryptString('https://example.test/hook'), 'secret' => Crypt::encryptString(str_repeat('a', 64)), 'events' => json_encode(['seller.contact.captured']), 'created_at' => now(), 'updated_at' => now()]);
        }
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'seller.contact.captured', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['conversation_id' => (string) Str::ulid(), 'contact_id' => (string) Str::ulid()]]);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
        Queue::assertPushed(ProcessFakeWebhookEvent::class);
        $late = (string) Str::ulid();
        DB::table('webhooks')->insert(['id' => $late, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'url' => Crypt::encryptString('https://example.test/late'), 'secret' => Crypt::encryptString(str_repeat('b', 64)), 'events' => json_encode(['seller.contact.captured']), 'created_at' => now(), 'updated_at' => now()]);
        app(WebhookOutbox::class)->consume($event->envelope['event_id']);
        app(WebhookOutbox::class)->consume($event->envelope['event_id']);
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $delivery = DB::table('webhook_deliveries')->first();
        self::assertSame($hook, $delivery->webhook_id);
        Queue::assertPushed(DeliverFakeWebhook::class, 1);
        (new DeliverFakeWebhook($delivery->id))->handle(app(FakeWebhookTransport::class));
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $delivery->id, 'event_id' => $event->envelope['event_id'], 'status' => 'simulated', 'attempts' => 1]);
        $this->assertDatabaseMissing('webhook_deliveries', ['webhook_id' => $late]);
        $this->assertDatabaseMissing('webhook_deliveries', ['webhook_id' => $otherHook]);
        Http::assertNothingSent();
    }
}
