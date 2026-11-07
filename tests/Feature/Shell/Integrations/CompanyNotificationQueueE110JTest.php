<?php

namespace Tests\Feature\Shell\Integrations;

use App\Shell\Integrations\CompanyNotifications\CompanyNotificationDelivery;
use App\Shell\Integrations\CompanyNotifications\DeliverCompanyNotification;
use App\Shell\Integrations\CompanyNotifications\DeliverFakeCompanyNotification;
use App\Shell\Integrations\CompanyNotifications\FakeCompanyNotificationTransport;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\NotificationMail;
use App\Shell\Notifications\NotificationProcessor;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CompanyNotificationQueueE110JTest extends TenancyTestCase
{
    public function test_company_delivery_is_disabled_without_fake_driver(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['runtime.notification_driver' => null, 'features.E0-04.defaults.external_delivery' => false]);
        [, $org, $brand] = $this->cabinet();
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['company_notifications' => ['report_emails' => ['owner@example.test'], 'matrix' => ['report_published' => ['email' => true]]]])]);
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'vision.report.published', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'report', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['publication_id' => (string) Str::ulid(), 'report_id' => (string) Str::ulid()]]);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $this->assertDatabaseCount('company_notification_deliveries', 0);
        Queue::assertNotPushed(DeliverFakeCompanyNotification::class);
        Http::assertNothingSent();
    }

    public function test_matrix_queues_only_enabled_brand_destinations_with_fake_transport(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['runtime.notification_driver' => 'fake']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        $settings = ['company_notifications' => ['telegram_chat' => Crypt::encryptString('123456'), 'report_emails' => ['owner@example.test'], 'matrix' => ['handoff' => ['email' => true, 'telegram' => true], 'report_published' => ['email' => true, 'telegram' => false], 'limit_80' => ['email' => true]]]];
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode($settings)]);
        foreach ([['seller.conversation.handed_off', ['reason' => 'want_human', 'task_id' => (string) Str::ulid()], $brand], ['vision.report.published', ['publication_id' => (string) Str::ulid(), 'report_id' => (string) Str::ulid()], $brand], ['seller.quota.threshold_reached', ['limit_key' => 'dialogs', 'fraction' => '0.7'], $brand], ['seller.quota.threshold_reached', ['limit_key' => 'dialogs', 'fraction' => '0.8'], $brand], ['seller.conversation.handed_off', ['reason' => 'want_human', 'task_id' => (string) Str::ulid()], $other]] as [$type, $data, $item]) {
            $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => $type, 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => $data]);
            DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
            app(NotificationProcessor::class)->process($event->envelope['event_id']);
            app(NotificationProcessor::class)->process($event->envelope['event_id']);
        }
        $this->assertDatabaseCount('company_notification_deliveries', 4);
        $this->assertSame(3, DB::table('company_notification_deliveries')->where('channel', 'email')->count());
        $this->assertSame(1, DB::table('company_notification_deliveries')->where('channel', 'telegram')->count());
        $this->assertDatabaseMissing('company_notification_deliveries', ['brand_id' => $other->id]);
        Queue::assertPushed(DeliverFakeCompanyNotification::class, 4);
        $transport = $this->mock(FakeCompanyNotificationTransport::class);
        $transport->shouldReceive('send')->times(3)->with('email', 'owner@example.test', \Mockery::type('string'));
        $transport->shouldReceive('send')->once()->with('telegram', '123456', \Mockery::type('string'));
        foreach (DB::table('company_notification_deliveries')->get() as $delivery) {
            self::assertStringNotContainsString('owner@example.test', $delivery->destination);
            (new DeliverFakeCompanyNotification($delivery->id))->handle($transport);
        }
        self::assertSame(4, DB::table('company_notification_deliveries')->where('status', 'simulated')->count());
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'vision.report.published', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'report', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['publication_id' => (string) Str::ulid(), 'report_id' => (string) Str::ulid()]]);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $pending = DB::table('company_notification_deliveries')->where('event_id', $event->envelope['event_id'])->first();
        $settings['company_notifications']['matrix']['report_published']['email'] = false;
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode($settings)]);
        (new DeliverFakeCompanyNotification($pending->id))->handle($transport);
        $this->assertDatabaseHas('company_notification_deliveries', ['id' => $pending->id, 'status' => 'cancelled']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->get('/integrations/notifications')->assertOk()->assertInertia(fn ($page) => $page->has('company_deliveries', 5)->missing('company_deliveries.0.destination'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->withHeader('X-Brand-Id', $other->id)->get('/integrations/notifications')->assertOk()->assertInertia(fn ($page) => $page->has('company_deliveries', 0));
        Http::assertNothingSent();
    }

    public function test_real_delivery_sends_email_and_telegram_once_flag_enabled(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        config(['features.E1-10.defaults.company_notification_delivery' => true, 'features.E0-04.defaults.telegram_bot_token' => 'test-token', 'runtime.notification_driver' => null, 'mail.default' => 'smtp']);
        [$owner, $org, $brand] = $this->cabinet();
        $settings = ['company_notifications' => ['telegram_chat' => Crypt::encryptString('654321'), 'report_emails' => ['owner@example.test'], 'matrix' => ['handoff' => ['email' => true, 'telegram' => true]]]];
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode($settings)]);
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $this->assertDatabaseCount('company_notification_deliveries', 2);
        Queue::assertPushed(DeliverCompanyNotification::class, 2);
        $emailDelivery = DB::table('company_notification_deliveries')->where('channel', 'email')->first();
        $telegramDelivery = DB::table('company_notification_deliveries')->where('channel', 'telegram')->first();
        $pending = \Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->with(\Mockery::type(NotificationMail::class))->andReturn(\Mockery::mock(SentMessage::class));
        Mail::shouldReceive('to')->once()->with('owner@example.test')->andReturn($pending);
        app(CompanyNotificationDelivery::class)->send($emailDelivery->id);
        $this->assertDatabaseHas('company_notification_deliveries', ['id' => $emailDelivery->id, 'status' => 'sent']);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        app(CompanyNotificationDelivery::class)->send($telegramDelivery->id);
        $this->assertDatabaseHas('company_notification_deliveries', ['id' => $telegramDelivery->id, 'status' => 'sent']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottest-token/sendMessage' && $request['chat_id'] === '654321');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->get('/integrations/notifications')->assertOk()->assertInertia(fn ($page) => $page->where('company_notification_delivery_available', true));
    }

    public function test_real_delivery_cancels_when_destination_no_longer_matches_settings(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        Queue::fake();
        config(['features.E1-10.defaults.company_notification_delivery' => true, 'runtime.notification_driver' => null, 'mail.default' => 'smtp']);
        [, $org, $brand] = $this->cabinet();
        $settings = ['company_notifications' => ['report_emails' => ['owner@example.test'], 'matrix' => ['handoff' => ['email' => true]]]];
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode($settings)]);
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $delivery = DB::table('company_notification_deliveries')->first();
        $settings['company_notifications']['matrix']['handoff']['email'] = false;
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode($settings)]);
        app(CompanyNotificationDelivery::class)->send($delivery->id);
        $this->assertDatabaseHas('company_notification_deliveries', ['id' => $delivery->id, 'status' => 'cancelled']);
        Mail::assertNothingSent();
    }
}
