<?php

namespace Tests\Feature\Shell\Notifications;

use App\Models\Brand;
use App\Models\Organization;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\Jobs\ProcessNotificationEvent;
use App\Shell\Notifications\OutboxDispatcher;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;
use Throwable;

final class OutboxTracePrivacyTest extends TestCase
{
    use DatabaseMigrations;

    private string|false $oldIgnoreArgs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($this->oldIgnoreArgs);
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if ($this->oldIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->oldIgnoreArgs);
        }
        parent::tearDown();
    }

    private function event(string $reason): DomainEvent
    {
        $org = Organization::create(['name' => 'Synthetic']);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($org->id, source: 'job'), fn () => Brand::create(['name' => 'Synthetic']));

        return new DomainEvent([
            'event_id' => (string) Str::ulid(), 'schema_version' => 1,
            'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(),
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null,
            'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1],
            'data' => ['reason' => $reason, 'task_id' => (string) Str::ulid()],
        ]);
    }

    private function assertSanitized(Throwable $error, string $marker): void
    {
        do {
            self::assertStringNotContainsString($marker, $error->getMessage());
            self::assertStringNotContainsString($marker, $error->getTraceAsString());
            foreach ($error->getTrace() as $frame) {
                self::assertNotSame('json_encode', $frame['function'] ?? null, 'Original JSON frame must not escape.');
                if (($frame['class'] ?? null) === OutboxDispatcher::class) {
                    foreach ($frame['args'] ?? [] as $argument) {
                        self::assertInstanceOf(\SensitiveParameterValue::class, $argument);
                    }
                }
            }
            $error = $error->getPrevious();
        } while ($error !== null);
    }

    public function test_invalid_utf8_does_not_expose_original_json_exception(): void
    {
        $event = $this->event("SYNTHETIC_UTF8_SECRET\xff");
        try {
            DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
            self::fail('Malformed UTF-8 should be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('Outbox operation could not be completed.', $error->getMessage());
            self::assertNull($error->getPrevious());
            $this->assertSanitized($error, 'SYNTHETIC_UTF8_SECRET');
        }
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        $this->assertDatabaseMissing('outbox_events', ['id' => $event->envelope['event_id']]);
        Queue::assertNotPushed(ProcessNotificationEvent::class);
    }

    public function test_postgres_jsonb_failure_has_no_sql_bindings_or_original_previous(): void
    {
        $event = $this->event("SYNTHETIC_SQL_SECRET\0");
        try {
            DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event));
            self::fail('PostgreSQL should reject NUL in JSONB.');
        } catch (QueryException $error) {
            self::assertSame('22P05', $error->getCode());
            self::assertSame('22P05', $error->errorInfo[0]);
            self::assertSame('[redacted]', $error->getSql());
            self::assertSame([], $error->getBindings());
            self::assertInstanceOf(PDOException::class, $error->getPrevious());
            self::assertNull($error->getPrevious()->getPrevious());
            $this->assertSanitized($error, 'SYNTHETIC_SQL_SECRET');
        }
        $this->assertDatabaseMissing('outbox_events', ['id' => $event->envelope['event_id']]);
        Queue::assertNotPushed(ProcessNotificationEvent::class);
    }

    public function test_normal_commit_and_idempotent_replay_still_dispatch_once(): void
    {
        $event = $this->event('synthetic');
        DB::transaction(function () use ($event): void {
            app(NotificationDispatcher::class)->enqueue($event);
            app(NotificationDispatcher::class)->enqueue($event);
        });
        $this->assertDatabaseHas('outbox_events', ['id' => $event->envelope['event_id']]);
        self::assertSame(1, DB::table('outbox_events')->where('id', $event->envelope['event_id'])->count());
        Queue::assertPushed(ProcessNotificationEvent::class, 1);
    }

    public function test_laravel_transaction_retries_sanitized_serialization_failure(): void
    {
        $event = $this->event('synthetic');
        $attempts = 0;
        DB::listen(function (QueryExecuted $query) use (&$attempts): void {
            if (str_contains(strtolower($query->sql), 'insert into "outbox_events"') && ++$attempts === 1) {
                $driver = new PDOException('SYNTHETIC_DRIVER_SECRET');
                $driver->errorInfo = ['40001', null, 'SYNTHETIC_DRIVER_SECRET'];
                throw new QueryException('pgsql', $query->sql, $query->bindings, $driver);
            }
        });
        DB::transaction(fn () => app(NotificationDispatcher::class)->enqueue($event), 2);
        self::assertSame(2, $attempts);
        self::assertSame(1, DB::table('outbox_events')->where('id', $event->envelope['event_id'])->count());
        Queue::assertPushed(ProcessNotificationEvent::class, 1);
    }
}
