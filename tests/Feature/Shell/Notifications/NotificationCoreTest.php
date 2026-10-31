<?php

namespace Tests\Feature\Shell\Notifications;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Notifications\ConfigureNotification;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\EventCatalog;
use App\Shell\Notifications\ExternalDelivery;
use App\Shell\Notifications\NotificationMail;
use App\Shell\Notifications\NotificationProcessor;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Shell\Notifications\Fixtures\ObservedRecord;
use Tests\Feature\Tenancy\TenancyTestCase;

final class NotificationCoreTest extends TenancyTestCase
{
    private function event(string $organization, string $brand): DomainEvent
    {
        return new DomainEvent([
            'event_id' => (string) Str::ulid(), 'schema_version' => 1,
            'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(),
            'organization_id' => $organization, 'brand_id' => $brand, 'actor_id' => null,
            'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1],
            'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()],
        ]);
    }

    public function test_registered_notification_catalog_matches_contract(): void
    {
        $events = json_decode(file_get_contents(base_path('docs/contracts/registry.json')), true, 512, JSON_THROW_ON_ERROR)['events'];
        $expected = array_keys(array_filter($events, fn ($item) => $item['notificationEvent']));
        $actual = array_keys(EventCatalog::TITLES);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_preferences_are_transactional_audited_and_per_tenant(): void
    {
        [$user, $org, $brand, , $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $user->id, $membership->id);
        app(TenantContextRunner::class)->run($context, fn () => app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'cabinet', false));
        $this->assertDatabaseHas('notification_settings', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $user->id, 'enabled' => false]);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'actor_id' => $user->id, 'action' => 'notification.settings.updated']);
        $bad = new TenantContext($org->id, $brand->id, $user->id, (string) Str::ulid());
        try {
            app(ConfigureNotification::class)->execute($bad, 'seller.conversation.handed_off', 'cabinet', true);
            $this->fail('Foreign membership accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_audit_redacts_secrets_and_rolls_back_with_mutation(): void
    {
        [$user, $org, $brand, , $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $user->id, $membership->id);
        try {
            DB::transaction(function () use ($context): void {
                app(AuditWriter::class)->record($context, new AuditEntry('changed', 'brand', $context->brandId, null, ['nested' => ['api_key' => 'must-not-appear'], 'enabled' => true]));
                $after = json_decode(DB::table('audit_logs')->value('after'), true);
                $this->assertSame('[REDACTED]', $after['nested']['api_key']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_outbox_idempotence_brand_access_and_preferences(): void
    {
        Queue::fake();
        [$owner, $org, $brand, $otherBrand, $membership] = $this->cabinet();
        [$member] = $this->member($org, 'member', [$brand->id]);
        [$excluded] = $this->member($org, 'member', [$otherBrand->id]);
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($context, fn () => app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'cabinet', false));
        $event = $this->event($org->id, $brand->id);
        DB::transaction(function () use ($event): void {
            app(NotificationDispatcher::class)->enqueue($event);
            app(NotificationDispatcher::class)->enqueue($event);
        });
        $this->assertDatabaseCount('outbox_events', 1);
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $this->assertDatabaseHas('cabinet_notifications', ['user_id' => $member->id, 'brand_id' => $brand->id]);
        $this->assertDatabaseMissing('cabinet_notifications', ['user_id' => $owner->id]);
        $this->assertDatabaseMissing('cabinet_notifications', ['user_id' => $excluded->id]);
        $this->assertDatabaseCount('cabinet_notifications', 1);
        $this->assertDatabaseCount('consumed_events', 1);
    }

    public function test_external_channels_use_preferences_fake_transports_and_retry(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        config(['features.E0-04.defaults.external_delivery' => true, 'features.E0-04.defaults.telegram_bot_token' => 'test-token', 'mail.default' => 'smtp']);
        [$user, $org, $brand, , $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $user->id, $membership->id);
        app(TenantContextRunner::class)->run($context, function () use ($context): void {
            app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'email', true);
            app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'telegram', true, '123456');
        });
        $this->assertNotSame('123456', DB::table('notification_settings')->where('channel', 'telegram')->value('destination'));
        $event = $this->event($org->id, $brand->id);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $ids = DB::table('notification_deliveries')->where('event_id', $event->envelope['event_id'])->pluck('id', 'channel');
        config(['features.E0-04.defaults.external_delivery' => false]);
        app(ExternalDelivery::class)->send($ids['email']);
        $this->assertSame('pending', DB::table('notification_deliveries')->where('id', $ids['email'])->value('status'));
        config(['features.E0-04.defaults.external_delivery' => true, 'mail.default' => 'log']);
        app(ExternalDelivery::class)->send($ids['email']);
        $this->assertSame('failed', DB::table('notification_deliveries')->where('id', $ids['email'])->value('status'));
        config(['mail.default' => 'smtp']);
        $pending = \Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->with(\Mockery::type(NotificationMail::class))->andReturn(\Mockery::mock(SentMessage::class));
        Mail::shouldReceive('to')->once()->with($user->email)->andReturn($pending);
        app(ExternalDelivery::class)->send($ids['email']);
        app(ExternalDelivery::class)->send($ids['telegram']);
        app(ExternalDelivery::class)->send($ids['telegram']);
        $this->assertSame('sent', DB::table('notification_deliveries')->where('id', $ids['email'])->value('status'));
        $this->assertSame(2, DB::table('notification_deliveries')->where('id', $ids['email'])->value('attempts'));
        $this->assertSame('sent', DB::table('notification_deliveries')->where('id', $ids['telegram'])->value('status'));
        $this->assertSame(1, DB::table('notification_deliveries')->where('id', $ids['telegram'])->value('attempts'));
        Http::assertSentCount(1);
    }

    public function test_rejected_event_and_rolled_back_outbox_leave_no_notifications(): void
    {
        [$user, $org, $brand] = $this->cabinet();
        $event = $this->event($org->id, $brand->id);
        try {
            DB::transaction(function () use ($event): void {
                app(NotificationDispatcher::class)->enqueue($event);
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('abort', $e->getMessage());
        }
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseCount('cabinet_notifications', 0);
        $data = $event->envelope;
        $data['data']['message_text'] = 'sensitive';
        $this->expectException(\InvalidArgumentException::class);
        new DomainEvent($data);
    }

    public function test_http_settings_endpoint_requires_authorized_active_brand_and_audits(): void
    {
        [$owner, $org, $brand, $otherBrand] = $this->cabinet();
        [$restricted] = $this->member($org, 'member', [$otherBrand->id]);
        $body = ['event_key' => 'seller.conversation.handed_off', 'channel' => 'cabinet', 'enabled' => false];
        $this->patchJson('/notifications/settings', $body)->assertUnauthorized();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->patchJson('/notifications/settings', $body)->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $owner->id, 'action' => 'notification.settings.updated']);
        $this->actingAs($restricted)->withSession(['brand_id' => $brand->id])->patchJson('/notifications/settings', $body)->assertNotFound();
        $this->assertDatabaseCount('notification_settings', 1);
    }

    public function test_observer_tracks_model_changes_with_secrets_redacted(): void
    {
        Schema::create('notification_observer_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('password');
        });
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($context, function (): void {
            DB::transaction(function (): void {
                $item = ObservedRecord::create(['name' => 'Before', 'password' => 'hidden']);
                $item->update(['name' => 'After']);
            });
        });
        $this->assertDatabaseCount('audit_logs', 2);
        $created = DB::table('audit_logs')->where('action', 'created')->first();
        $updated = DB::table('audit_logs')->where('action', 'updated')->first();
        $this->assertSame('[REDACTED]', json_decode($created->after, true)['password']);
        $this->assertSame('Before', json_decode($updated->before, true)['name']);
        $this->assertSame('After', json_decode($updated->after, true)['name']);
    }

    public function test_ambiguous_external_outcomes_never_retry_even_with_retry_failed(): void
    {
        Queue::fake();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('transport timeout with sensitive detail');
        });
        config(['features.E0-04.defaults.external_delivery' => true, 'features.E0-04.defaults.telegram_bot_token' => 'test-token', 'mail.default' => 'smtp']);
        [$user, $org, $brand, , $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $user->id, $membership->id);
        app(TenantContextRunner::class)->run($context, function () use ($context): void {
            app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'email', true);
            app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'telegram', true, '123456');
        });
        $event = $this->event($org->id, $brand->id);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $ids = DB::table('notification_deliveries')->where('event_id', $event->envelope['event_id'])->pluck('id', 'channel');
        $pending = \Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andReturn(null);
        Mail::shouldReceive('to')->once()->with($user->email)->andReturn($pending);
        app(ExternalDelivery::class)->send($ids['email']);
        app(ExternalDelivery::class)->send($ids['telegram']);
        foreach (['email', 'telegram'] as $channel) {
            $delivery = DB::table('notification_deliveries')->where('id', $ids[$channel])->first();
            $this->assertSame('uncertain', $delivery->status);
            $this->assertSame('delivery_uncertain', $delivery->last_error);
            $this->assertNull($delivery->sent_at);
            $this->assertSame(1, $delivery->attempts);
            app(ExternalDelivery::class)->send($ids[$channel]);
        }
        $this->artisan('notifications:drain', ['--retry-failed' => true])->assertSuccessful();
        $this->assertSame(1, $calls);
        $this->assertSame(1, DB::table('notification_deliveries')->where('id', $ids['telegram'])->value('attempts'));
        $this->assertSame(1, DB::table('notification_deliveries')->where('id', $ids['email'])->value('attempts'));
    }

    public function test_telegram_unconfirmed_success_body_is_uncertain(): void
    {
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 200)]);
        config(['features.E0-04.defaults.external_delivery' => true, 'features.E0-04.defaults.telegram_bot_token' => 'test-token']);
        [$user, $org, $brand, , $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $user->id, $membership->id);
        app(TenantContextRunner::class)->run($context, fn () => app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'telegram', true, '123456'));
        $event = $this->event($org->id, $brand->id);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $id = DB::table('notification_deliveries')->where('event_id', $event->envelope['event_id'])->where('channel', 'telegram')->value('id');
        app(ExternalDelivery::class)->send($id);
        $this->assertDatabaseHas('notification_deliveries', ['id' => $id, 'status' => 'uncertain', 'last_error' => 'delivery_uncertain']);
        Http::assertSentCount(1);
    }

    public function test_mail_transport_exception_is_uncertain_and_does_not_retry(): void
    {
        Queue::fake();
        config(['features.E0-04.defaults.external_delivery' => true, 'mail.default' => 'smtp']);
        [$user, $org, $brand, , $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $user->id, $membership->id);
        app(TenantContextRunner::class)->run($context, fn () => app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'email', true));
        $event = $this->event($org->id, $brand->id);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $id = DB::table('notification_deliveries')->where('event_id', $event->envelope['event_id'])->where('channel', 'email')->value('id');
        $pending = \Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp timeout with sensitive detail'));
        Mail::shouldReceive('to')->once()->with($user->email)->andReturn($pending);
        app(ExternalDelivery::class)->send($id);
        app(ExternalDelivery::class)->send($id);
        $this->artisan('notifications:drain', ['--retry-failed' => true])->assertSuccessful();
        $this->assertDatabaseHas('notification_deliveries', ['id' => $id, 'status' => 'uncertain', 'last_error' => 'delivery_uncertain', 'attempts' => 1]);
    }
}
