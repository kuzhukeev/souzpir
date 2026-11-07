<?php

namespace Tests\Feature\Shell\Integrations;

use App\Shell\Integrations\CompanyNotifications\DeliverFakeCompanyNotification;
use App\Shell\Integrations\CompanyNotifications\FakeCompanyNotificationTransport;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\NotificationProcessor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_external_delivery_enabled_prop_reflects_driver_and_feature_flag(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);

        config(['runtime.notification_driver' => 'fake', 'features.E0-04.defaults.external_delivery' => true]);
        $this->get('/integrations/notifications')->assertOk()->assertInertia(fn ($page) => $page->where('external_delivery_enabled', false));

        config(['runtime.notification_driver' => null, 'features.E0-04.defaults.external_delivery' => false]);
        $this->get('/integrations/notifications')->assertOk()->assertInertia(fn ($page) => $page->where('external_delivery_enabled', false));

        config(['runtime.notification_driver' => null, 'features.E0-04.defaults.external_delivery' => true]);
        $this->get('/integrations/notifications')->assertOk()->assertInertia(fn ($page) => $page->where('external_delivery_enabled', true));
    }
}
