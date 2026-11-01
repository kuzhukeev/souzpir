<?php

namespace Tests\Feature\Shell\Notifications;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Providers\Features\FeatureRegistry;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\Contracts\NotificationRouter;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\Jobs\ProcessNotificationEvent;
use App\Shell\Notifications\NotificationDeferred;
use App\Shell\Notifications\NotificationProcessor;
use App\Shell\Notifications\NotificationRouting;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class DeferralRouter implements NotificationRouter
{
    public array $deferred = [];

    public array $failures = [];

    public bool $enabled = false;

    public int $routes = 0;

    public ?int $deferAt = null;

    public function route(DomainEvent $event, Membership $member, string $channel): string
    {
        $this->routes++;
        if ($this->deferAt === $this->routes) {
            throw new NotificationDeferred('digest_disabled');
        }
        $id = $event->envelope['event_id'];
        if (isset($this->failures[$id])) {
            throw new RuntimeException('raw-sensitive-exception-marker');
        }
        if (! $this->enabled && isset($this->deferred[$id])) {
            throw new NotificationDeferred('digest_disabled');
        }

        return 'immediate';
    }

    public function allowsImmediate(DomainEvent $event, Membership $member, string $channel): bool
    {
        return true;
    }
}

final class OutboxDeferralTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    private User $user;

    private Organization $org;

    private Brand $brand;

    private DeferralRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(config('runtime.test_database'), DB::connection()->getDatabaseName());
        self::assertNotSame(config('runtime.preview_database'), DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Mail::fake();
        $this->user = User::create(['name' => 'Owner', 'email' => 'outbox-test@example.test', 'password' => 'test-only-password']);
        $this->org = Organization::create(['name' => 'Cabinet', 'owner_user_id' => $this->user->id]);
        [$this->brand] = app(TenantContextRunner::class)->run(new TenantContext($this->org->id, source: 'job'), fn () => [
            Brand::create(['name' => 'Brand']), Membership::create(['user_id' => $this->user->id, 'role' => 'owner']),
        ]);
        $this->router = new DeferralRouter;
        $features = \Mockery::mock(FeatureRegistry::class);
        $features->shouldReceive('extensions')->andReturn(['notifications.router' => DeferralRouter::class]);
        app()->instance(DeferralRouter::class, $this->router);
        app()->instance(NotificationRouting::class, new NotificationRouting($features));
    }

    private function event(): DomainEvent
    {
        return new DomainEvent([
            'event_id' => (string) Str::ulid(), 'schema_version' => 1,
            'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(),
            'organization_id' => $this->org->id, 'brand_id' => $this->brand->id, 'actor_id' => null,
            'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1],
            'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()],
        ]);
    }

    private function insert(DomainEvent $event, bool $processed = false): void
    {
        DB::table('outbox_events')->insert(['id' => $event->envelope['event_id'], 'organization_id' => $this->org->id,
            'brand_id' => $this->brand->id, 'envelope' => json_encode($event->envelope, JSON_THROW_ON_ERROR),
            'created_at' => '2026-01-01 00:00:00+00', 'processed_at' => $processed ? now() : null]);
    }

    public function test_sync_after_commit_retains_business_write_and_rolls_back_partial_processing(): void
    {
        config(['queue.default' => 'sync']);
        config(['features.E0-04.defaults.external_delivery' => true, 'mail.default' => 'smtp']);
        foreach (['email', 'telegram'] as $channel) {
            DB::table('notification_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->org->id,
                'brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'event_key' => 'seller.conversation.handed_off',
                'channel' => $channel, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $event = $this->event();
        // Cabinet and email are inserted; email schedules afterCommit before telegram defers.
        $this->router->deferAt = 3;
        Log::spy();
        DB::transaction(function () use ($event): void {
            DB::table('organizations')->where('id', $this->org->id)->update(['name' => 'Committed change']);
            app(NotificationDispatcher::class)->enqueue($event);
        });
        self::assertSame('Committed change', DB::table('organizations')->where('id', $this->org->id)->value('name'));
        self::assertNull(DB::table('outbox_events')->where('id', $event->envelope['event_id'])->value('processed_at'));
        self::assertSame(0, DB::table('notification_deliveries')->count());
        self::assertSame(0, DB::table('consumed_events')->count());
        self::assertSame(3, $this->router->routes);
        Log::shouldHaveReceived('notice')->once()->with('Notification processing deferred.', ['reason' => 'digest_disabled']);
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_cursor_passes_more_than_100_deferred_events_then_wraps_without_duplicates(): void
    {
        Queue::fake();
        $events = [];
        for ($i = 0; $i < 102; $i++) {
            $event = $this->event();
            $this->insert($event);
            $events[] = $event->envelope['event_id'];
        }
        sort($events, SORT_STRING);
        $this->router->deferred = array_fill_keys(array_slice($events, 0, 101), true);

        self::assertSame(0, Artisan::call('notifications:drain'));
        self::assertSame(102, DB::table('outbox_events')->whereNull('processed_at')->count());
        self::assertSame($events[99], DB::table('notification_outbox_cursors')->where('name', 'notifications')->value('last_event_id'));
        self::assertSame(0, Artisan::call('notifications:drain'));
        self::assertNotNull(DB::table('outbox_events')->where('id', $events[101])->value('processed_at'));
        self::assertSame(101, DB::table('outbox_events')->whereNull('processed_at')->count());
        $this->router->enabled = true;
        self::assertSame(0, Artisan::call('notifications:drain'));
        self::assertSame(0, Artisan::call('notifications:drain'));
        self::assertSame(0, Artisan::call('notifications:drain'));
        self::assertSame(0, DB::table('outbox_events')->whereNull('processed_at')->count());
        self::assertSame(102, DB::table('consumed_events')->count());
        self::assertSame(102, DB::table('cabinet_notifications')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_deferred_does_not_block_permitted_external_delivery_or_retry_uncertain(): void
    {
        Queue::fake();
        config(['features.E0-04.defaults.external_delivery' => true, 'features.E0-04.defaults.telegram_bot_token' => 'synthetic']);
        $event = $this->event();
        $this->insert($event);
        $this->router->deferred[$event->envelope['event_id']] = true;
        $old = $this->event();
        $this->insert($old, true);
        DB::table('notification_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->org->id,
            'brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'event_key' => 'seller.conversation.handed_off',
            'channel' => 'telegram', 'enabled' => true, 'destination' => Crypt::encryptString('123456'), 'created_at' => now(), 'updated_at' => now()]);
        $makeDelivery = function (string $status) use ($old): string {
            $id = (string) Str::ulid();
            DB::table('notification_deliveries')->insert(['id' => $id, 'organization_id' => $this->org->id, 'brand_id' => $this->brand->id,
                'event_id' => $old->envelope['event_id'], 'user_id' => $this->user->id, 'channel' => 'telegram', 'status' => $status,
                'attempts' => $status === 'uncertain' ? 1 : 0, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        };
        $id = $makeDelivery('pending');
        Http::fake(function () {
            self::assertSame(0, DB::transactionLevel());

            return Http::response(['ok' => true], 200);
        });
        self::assertSame(0, Artisan::call('notifications:drain'));
        self::assertSame('sent', DB::table('notification_deliveries')->where('id', $id)->value('status'));
        self::assertNull(DB::table('outbox_events')->where('id', $event->envelope['event_id'])->value('processed_at'));
        Http::assertSentCount(1);
        DB::table('notification_deliveries')->where('id', $id)->update(['status' => 'uncertain']);
        self::assertSame(0, Artisan::call('notifications:drain', ['--retry-failed' => true]));
        self::assertSame('uncertain', DB::table('notification_deliveries')->where('id', $id)->value('status'));
        Http::assertSentCount(1);
    }

    public function test_unexpected_errors_are_sanitized_and_other_events_continue(): void
    {
        Queue::fake();
        $first = $this->event();
        $second = $this->event();
        $this->insert($first);
        $this->insert($second);
        $this->router->failures[$first->envelope['event_id']] = true;
        Log::spy();
        self::assertSame(1, Artisan::call('notifications:drain'));
        Log::shouldHaveReceived('error')->once()->with('Notification event processing failed.', ['code' => 'outbox_processing_failed']);
        self::assertStringNotContainsString('raw-sensitive-exception-marker', Artisan::output());
        self::assertNull(DB::table('outbox_events')->where('id', $first->envelope['event_id'])->value('processed_at'));
        self::assertNotNull(DB::table('outbox_events')->where('id', $second->envelope['event_id'])->value('processed_at'));
        self::assertSame(1, DB::table('cabinet_notifications')->count());
        $this->expectException(RuntimeException::class);
        (new ProcessNotificationEvent($first->envelope['event_id']))->handle(app(NotificationProcessor::class));
    }

    public function test_failed_deferral_logging_does_not_block_other_events_and_fails_safely(): void
    {
        Queue::fake();
        $first = $this->event();
        $second = $this->event();
        $this->insert($first);
        $this->insert($second);
        $this->router->deferred[$first->envelope['event_id']] = true;
        Log::shouldReceive('notice')->once()->andThrow(new RuntimeException('secret-logger-error'));
        self::assertSame(1, Artisan::call('notifications:drain'));
        self::assertStringNotContainsString('secret-logger-error', Artisan::output());
        self::assertNull(DB::table('outbox_events')->where('id', $first->envelope['event_id'])->value('processed_at'));
        self::assertNotNull(DB::table('outbox_events')->where('id', $second->envelope['event_id'])->value('processed_at'));
    }

    public function test_two_scanners_reserve_distinct_pages_with_real_postgresql_locks(): void
    {
        for ($i = 0; $i < 102; $i++) {
            $this->insert($this->event());
        }
        $firstInput = new InputStream;
        $secondInput = new InputStream;
        $command = [PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/cursor-worker.php'];
        $first = new Process($command, base_path(), ['APP_ENV' => 'testing'], $firstInput, 45);
        $second = new Process($command, base_path(), ['APP_ENV' => 'testing'], $secondInput, 45);
        try {
            $first->start();
            $second->start();
            $deadline = microtime(true) + 30;
            do {
                $first->isRunning();
                $second->isRunning();
                if (str_contains($first->getOutput(), 'ready') && str_contains($second->getOutput(), 'ready')) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertStringContainsString('ready', $first->getOutput(), $first->getErrorOutput());
            self::assertStringContainsString('ready', $second->getOutput(), $second->getErrorOutput());
            $firstInput->write("go\n");
            $secondInput->write("go\n");
            $first->wait();
            $second->wait();
            self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $a = json_decode(trim(str_replace('ready', '', $first->getOutput())), true, flags: JSON_THROW_ON_ERROR);
            $b = json_decode(trim(str_replace('ready', '', $second->getOutput())), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(102, count($a) + count($b));
            self::assertSame([], array_values(array_intersect($a, $b)));
            self::assertSame(102, count(array_unique([...$a, ...$b])));
        } finally {
            if ($first->isRunning()) {
                $first->stop();
            }
            if ($second->isRunning()) {
                $second->stop();
            }
            $firstInput->close();
            $secondInput->close();
        }
    }

    public function test_scanner_rejects_nested_transaction_before_processing_or_transport(): void
    {
        $event = $this->event();
        $this->insert($event);
        DB::beginTransaction();
        try {
            self::assertSame(1, Artisan::call('notifications:drain'));
            self::assertSame(0, DB::table('notification_outbox_cursors')->count());
            self::assertNull(DB::table('outbox_events')->where('id', $event->envelope['event_id'])->value('processed_at'));
        } finally {
            DB::rollBack();
        }
    }
}
