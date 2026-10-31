<?php

namespace Tests\Feature\Shell\Notifications;

use App\Models\Membership;
use App\Providers\Features\FeatureRegistry;
use App\Shell\Notifications\ConfigureNotification;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\Contracts\NotificationRouter;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\ExternalDelivery;
use App\Shell\Notifications\NotificationProcessor;
use App\Shell\Notifications\NotificationRouting;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TestNotificationRouter implements NotificationRouter
{
    public string $decision = 'immediate';

    public bool $allowed = true;

    public int $routed = 0;

    public function route(DomainEvent $event, Membership $member, string $channel): string
    {
        $this->routed++;

        return $this->decision;
    }

    public function allowsImmediate(DomainEvent $event, Membership $member, string $channel): bool
    {
        return $this->allowed;
    }
}

final class NotificationRoutingTest extends TenancyTestCase
{
    private function router(): TestNotificationRouter
    {
        $router = new TestNotificationRouter;
        $features = \Mockery::mock(FeatureRegistry::class);
        $features->shouldReceive('extensions')->andReturn(['notifications.router' => TestNotificationRouter::class]);
        app()->instance(TestNotificationRouter::class, $router);
        app()->instance(NotificationRouting::class, new NotificationRouting($features));

        return $router;
    }

    private function event(string $org, string $brand): DomainEvent
    {
        return new DomainEvent([
            'event_id' => (string) Str::ulid(), 'schema_version' => 1,
            'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(),
            'organization_id' => $org, 'brand_id' => $brand, 'actor_id' => null,
            'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1],
            'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()],
        ]);
    }

    public function test_routing_respects_personal_opt_out_and_invalid_decisions_roll_back(): void
    {
        Queue::fake();
        [$user, $org, $brand, , $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $user->id, $membership->id);
        $router = $this->router();
        app(TenantContextRunner::class)->run($context, fn () => app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'cabinet', false));
        $first = $this->event($org->id, $brand->id);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($first));
        app(NotificationProcessor::class)->process($first->envelope['event_id']);
        self::assertSame(0, $router->routed);
        self::assertSame(0, DB::table('notification_deliveries')->count());

        app(TenantContextRunner::class)->run($context, fn () => app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'cabinet', true));
        $second = $this->event($org->id, $brand->id);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($second));
        $router->decision = 'digest';
        self::assertSame($router, app(TestNotificationRouter::class));
        self::assertSame('digest', app(NotificationRouting::class)->route($second, $membership, 'cabinet'));
        $router->decision = 'invalid';
        self::assertTrue((bool) DB::table('notification_settings')->where('user_id', $user->id)->where('event_key', 'seller.conversation.handed_off')->where('channel', 'cabinet')->value('enabled'));
        self::assertNull(DB::table('outbox_events')->where('id', $second->envelope['event_id'])->value('processed_at'));
        try {
            app(NotificationProcessor::class)->process($second->envelope['event_id']);
            self::fail('Invalid routing decision accepted.');
        } catch (\LogicException) {
            self::assertNull(DB::table('outbox_events')->where('id', $second->envelope['event_id'])->value('processed_at'));
            self::assertSame(0, DB::table('notification_deliveries')->count());
        }
        $router->decision = 'digest';
        app(NotificationProcessor::class)->process($second->envelope['event_id']);
        self::assertSame(0, DB::table('notification_deliveries')->count());
        self::assertNotNull(DB::table('outbox_events')->where('id', $second->envelope['event_id'])->value('processed_at'));
    }

    public function test_external_delivery_rechecks_routing_policy_before_sending(): void
    {
        Queue::fake();
        Mail::fake();
        config(['features.E0-04.defaults.external_delivery' => true, 'mail.default' => 'smtp']);
        [$user, $org, $brand, , $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $user->id, $membership->id);
        app(TenantContextRunner::class)->run($context, fn () => app(ConfigureNotification::class)->execute($context, 'seller.conversation.handed_off', 'email', true));
        $router = $this->router();
        $event = $this->event($org->id, $brand->id);
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $id = DB::table('notification_deliveries')->where('event_id', $event->envelope['event_id'])->where('channel', 'email')->value('id');
        self::assertNotNull($id);
        $router->allowed = false;
        app(ExternalDelivery::class)->send($id);
        self::assertSame('failed', DB::table('notification_deliveries')->where('id', $id)->value('status'));
        Mail::assertNothingSent();
    }
}
