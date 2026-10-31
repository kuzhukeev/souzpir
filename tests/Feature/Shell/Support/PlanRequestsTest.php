<?php

namespace Tests\Feature\Shell\Support;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\NotificationProcessor;
use App\Shell\Support\Contracts\PlanRequestCommand;
use App\Shell\Support\Contracts\PlanRequestDeliveryStatus;
use App\Shell\Support\Contracts\PlanRequestPort;
use App\Shell\Support\Contracts\PlanRequestResult;
use App\Shell\Support\TicketMail;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Feature\Shell\Support\Fixtures\IsolatedPlanTestDatabase;

final class PlanRequestsTest extends SupportTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->make(Kernel::class)->bootstrap();
        IsolatedPlanTestDatabase::assert($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->post('/_test/plan-request', function (Request $request) {
            $input = $request->validate(['plan_id' => ['required', 'ulid'], 'submission_key' => ['required', 'ulid']]);
            $result = app(PlanRequestPort::class)->submit($request, new PlanRequestCommand($input['plan_id'], $input['submission_key']));

            return response()->json(['ticket_id' => $result->ticketId, 'created' => $result->created,
                'delivery_status' => $result->deliveryStatus->value], $result->created ? 201 : 200);
        });
    }

    private function plan(bool $public = true): string
    {
        $id = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $id, 'code' => strtolower($id), 'name' => 'Тестовый тариф', 'is_public' => $public, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function submit(User $user, Organization $org, Brand $brand, Membership $member, PlanRequestCommand $command): PlanRequestResult
    {
        $request = Request::create('/billing/plans/request', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);

        return app(TenantContextRunner::class)->run(new TenantContext($org->id, $brand->id, $user->id, $member->id),
            fn () => app(PlanRequestPort::class)->submit($request, $command));
    }

    public function test_owner_creates_one_ticket_outbox_and_audit_and_receives_accurate_delivery_status(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->signIn($owner, $brand);
        $plan = $this->plan();
        $key = (string) Str::ulid();
        $result = $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($plan, $key));
        self::assertTrue($result->created);
        self::assertSame(PlanRequestDeliveryStatus::NotConfigured, $result->deliveryStatus);
        $ticket = DB::table('tickets')->where('id', $result->ticketId)->sole();
        self::assertSame('plan_request', $ticket->type);
        self::assertSame($org->id, $ticket->organization_id);
        self::assertSame($owner->id, $ticket->user_id);
        self::assertStringContainsString($plan, $ticket->body);
        self::assertSame(0, DB::table('tickets')->where('type', 'support')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'billing.plan_request.created')->where('event_id', $result->ticketId)->count());
        $event = DB::table('outbox_events')->where('id', $result->ticketId)->sole();
        $envelope = json_decode($event->envelope, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['ticket_id' => $result->ticketId], $envelope['data']);
        self::assertSame($brand->id, $event->brand_id);
        self::assertStringNotContainsString('Тестовый тариф', $event->envelope);
        app(NotificationProcessor::class)->process($result->ticketId);
        self::assertSame([$owner->id], DB::table('cabinet_notifications')->where('event_key', 'shell.plan_request.created')->pluck('user_id')->all());
        self::assertNotContains($admin->id, DB::table('cabinet_notifications')->pluck('user_id')->all());
        self::assertNotContains($viewer->id, DB::table('cabinet_notifications')->pluck('user_id')->all());

        DB::table('plans')->where('id', $plan)->update(['is_public' => false]);
        $again = $this->submit($owner, $org, $brand, $member, new PlanRequestCommand(strtolower($plan), strtolower($key)));
        self::assertFalse($again->created);
        self::assertSame($result->ticketId, $again->ticketId);
        self::assertSame(PlanRequestDeliveryStatus::NotConfigured, $again->deliveryStatus);
        self::assertSame(1, DB::table('tickets')->count());
        self::assertSame(1, DB::table('outbox_events')->where('id', $result->ticketId)->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_wrong_role_brand_and_non_public_plan_are_rejected_without_writes(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        [$admin, $adminMember] = $this->member($org, 'admin', [$brand->id]);
        $public = $this->plan();
        $private = $this->plan(false);
        $this->signIn($admin, $brand);
        try {
            $this->submit($admin, $org, $brand, $adminMember, new PlanRequestCommand($public, (string) Str::ulid()));
            self::fail('Admin must not manage billing.');
        } catch (AuthorizationException $error) {
            self::assertNotNull($error);
        }
        $this->signIn($owner, $brand);
        foreach ([[$brand, $private], [$other, $public]] as [$selected, $plan]) {
            try {
                $this->submit($owner, $org, $selected, $member, new PlanRequestCommand($plan, (string) Str::ulid()));
                self::fail('Private plan and mismatched session brand must fail.');
            } catch (HttpException $error) {
                self::assertContains($error->getStatusCode(), [403, 404]);
            }
        }
        self::assertSame(0, DB::table('tickets')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
    }

    public function test_reused_key_with_different_plan_conflicts_and_enqueue_failure_rolls_back(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->signIn($owner, $brand);
        $first = $this->plan();
        $second = $this->plan();
        $key = (string) Str::ulid();
        $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($first, $key));
        try {
            $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($second, $key));
            self::fail('A key cannot be reused for another request.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        $this->mock(NotificationDispatcher::class)->shouldReceive('enqueue')->once()->andThrow(new RuntimeException('test dispatch failure'));
        try {
            $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($second, (string) Str::ulid()));
            self::fail('Dispatch failure must abort the transaction.');
        } catch (RuntimeException $error) {
            self::assertSame('test dispatch failure', $error->getMessage());
        }
        self::assertSame(1, DB::table('tickets')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'billing.plan_request.created')->count());
        self::assertSame(1, DB::table('outbox_events')->count());
    }

    public function test_audit_failure_rolls_back_ticket_and_prevents_outbox_and_delivery(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->signIn($owner, $brand);
        $plan = $this->plan();
        $this->mock(AuditWriter::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('synthetic audit failure'));
        try {
            $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($plan, (string) Str::ulid()));
            self::fail('Audit failure must abort the transaction.');
        } catch (RuntimeException $error) {
            self::assertSame('synthetic audit failure', $error->getMessage());
        }
        self::assertSame(0, DB::table('tickets')->count());
        self::assertSame(0, DB::table('audit_logs')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_fake_delivery_is_uncertain_and_duplicate_does_not_resend(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->signIn($owner, $brand);
        config(['features.E1-12.defaults.mail_enabled' => true, 'features.E1-12.defaults.smtp_enabled' => true,
            'features.E1-12.defaults.mailer' => 'smtp', 'features.E1-12.defaults.recipient' => 'support@example.test']);
        $command = new PlanRequestCommand($this->plan(), (string) Str::ulid());
        $first = $this->submit($owner, $org, $brand, $member, $command);
        $second = $this->submit($owner, $org, $brand, $member, $command);
        self::assertSame(PlanRequestDeliveryStatus::Uncertain, $first->deliveryStatus);
        self::assertSame(PlanRequestDeliveryStatus::Uncertain, $second->deliveryStatus);
        Mail::assertSent(TicketMail::class, 1);
        Mail::assertSent(TicketMail::class, fn (TicketMail $mail) => str_contains($mail->render(), 'Заявка на смену тарифа'));
        Http::assertNothingSent();
    }

    public function test_configured_but_disabled_support_delivery_is_not_reported_as_sent(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->signIn($owner, $brand);
        config(['features.E1-12.defaults.recipient' => 'support@example.test', 'features.E1-12.defaults.mail_enabled' => false]);
        $result = $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($this->plan(), (string) Str::ulid()));
        self::assertSame(PlanRequestDeliveryStatus::Disabled, $result->deliveryStatus);
        self::assertSame('disabled', DB::table('tickets')->where('id', $result->ticketId)->value('delivery_status'));
        Mail::assertNothingSent();
    }

    public function test_other_organization_cannot_receive_notification_and_archived_brand_suppresses_delivery(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        [$otherOwner, $otherOrg, $otherBrand, , $otherMember] = $this->cabinet();
        $this->signIn($owner, $brand);
        $plan = $this->plan();
        $first = $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($plan, (string) Str::ulid()));
        $this->signIn($otherOwner, $otherBrand);
        $second = $this->submit($otherOwner, $otherOrg, $otherBrand, $otherMember, new PlanRequestCommand($plan, (string) Str::ulid()));
        app(NotificationProcessor::class)->process($first->ticketId);
        app(NotificationProcessor::class)->process($second->ticketId);
        self::assertSame([$owner->id], DB::table('cabinet_notifications')->where('organization_id', $org->id)->pluck('user_id')->all());
        self::assertSame([$otherOwner->id], DB::table('cabinet_notifications')->where('organization_id', $otherOrg->id)->pluck('user_id')->all());

        $third = $this->submit($otherOwner, $otherOrg, $otherBrand, $otherMember, new PlanRequestCommand($plan, (string) Str::ulid()));
        DB::table('brands')->where('id', $otherBrand->id)->update(['status' => 'archived']);
        app(NotificationProcessor::class)->process($third->ticketId);
        self::assertSame(0, DB::table('notification_deliveries')->where('event_id', $third->ticketId)->count());
    }

    public function test_revoked_session_is_rejected_before_creating_ticket(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->signIn($owner, $brand);
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        try {
            $this->submit($owner, $org, $brand, $member, new PlanRequestCommand($this->plan(), (string) Str::ulid()));
            self::fail('A revoked session cannot create a request.');
        } catch (AuthenticationException $error) {
            self::assertNotNull($error);
        }
        self::assertSame(0, DB::table('tickets')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
    }

    public function test_http_boundary_resolves_tenant_and_rejects_forged_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->signIn($owner, $brand);
        $input = ['plan_id' => $this->plan(), 'submission_key' => (string) Str::ulid()];
        $this->withHeader('X-Brand-Id', $other->id)->postJson('/_test/plan-request', $input)->assertNotFound();
        $this->flushHeaders()->postJson('/_test/plan-request', $input)->assertCreated()->assertJsonPath('delivery_status', 'not_configured');
        $this->postJson('/_test/plan-request', $input)->assertOk()->assertJsonPath('created', false);
        self::assertSame(1, DB::table('tickets')->where('organization_id', $org->id)->count());
        self::assertSame(1, DB::table('outbox_events')->where('brand_id', $brand->id)->count());
    }

    public function test_database_guard_rejects_mismatched_test_preview_and_worker_database(): void
    {
        $testDatabase = config('runtime.test_database');
        $previewDatabase = config('runtime.preview_database');
        try {
            config(['runtime.test_database' => null]);
            try {
                IsolatedPlanTestDatabase::assert(app());
                self::fail('Missing test database must be rejected.');
            } catch (\LogicException) {
                self::assertTrue(true);
            }
            config(['runtime.test_database' => 'souzpir_test_different']);
            try {
                IsolatedPlanTestDatabase::assert(app());
                self::fail('Unexpected test database must be rejected.');
            } catch (\LogicException) {
                self::assertTrue(true);
            }
            config(['runtime.test_database' => $testDatabase, 'runtime.preview_database' => $testDatabase]);
            try {
                IsolatedPlanTestDatabase::assert(app());
                self::fail('The preview database must never be accepted.');
            } catch (\LogicException) {
                self::assertTrue(true);
            }
            config(['runtime.preview_database' => $previewDatabase]);
            try {
                IsolatedPlanTestDatabase::assert(app(), 'souzpir_test_different');
                self::fail('The worker database must match the parent.');
            } catch (\LogicException) {
                self::assertTrue(true);
            }
            IsolatedPlanTestDatabase::assert(app(), $testDatabase);
        } finally {
            config(['runtime.test_database' => $testDatabase, 'runtime.preview_database' => $previewDatabase]);
        }
    }

    public function test_two_processes_with_distinct_sessions_create_one_request_and_one_fake_delivery(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $plan = $this->plan();
        $key = (string) Str::ulid();
        $directory = base_path('.local/runtime/plan-request-race-'.Str::lower(Str::random(20)));
        mkdir($directory, 0700, true);
        foreach (['storage/app/private', 'storage/app/public', 'storage/framework/views', 'storage/framework/cache/data',
            'storage/framework/cache/locks', 'storage/framework/sessions', 'storage/logs', 'cache'] as $path) {
            mkdir($directory.'/'.$path, 0700, true);
        }
        $processes = [];
        $locked = false;
        try {
            foreach ([1, 2] as $number) {
                $processes[$number] = $this->raceWorker($directory, ['number' => $number, 'user' => $owner->id,
                    'organization' => $org->id, 'brand' => $brand->id, 'member' => $member->id, 'plan' => $plan, 'key' => $key]);
                $processes[$number]->start();
            }
            $pids = [];
            foreach ($processes as $number => $process) {
                $marker = $directory.'/ready-'.$number;
                $deadline = microtime(true) + 30;
                while (! is_file($marker) && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                self::assertFileExists($marker, 'Worker could not initialize: '.$process->getErrorOutput());
                $pids[] = (int) file_get_contents($marker);
            }
            self::assertCount(2, array_unique($pids));
            self::assertSame(2, DB::table('auth_session_registry')->where('user_id', $owner->id)->whereNull('revoked_at')->count());
            DB::beginTransaction();
            $locked = true;
            DB::table('organizations')->where('id', $org->id)->lockForUpdate()->sole();
            file_put_contents($directory.'/go', 'go', LOCK_EX);
            $deadline = microtime(true) + 15;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $blocked = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
                if ($blocked === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, 'Both distinct database sessions must be blocked before the lock is released.');
            DB::commit();
            $locked = false;

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                self::assertJson($process->getOutput(), 'Worker produced invalid JSON: '.$process->getErrorOutput().' '.$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $created = array_column($results, 'created');
            sort($created);
            self::assertSame([false, true], $created);
            self::assertSame($results[0]['ticket'], $results[1]['ticket']);
            self::assertSame(1, array_sum(array_column($results, 'fake_mail_count')));
            self::assertSame(1, DB::table('tickets')->where('organization_id', $org->id)->where('submission_key', $key)->count());
            self::assertSame(1, DB::table('audit_logs')->where('organization_id', $org->id)->where('action', 'billing.plan_request.created')->count());
            self::assertSame(1, DB::table('outbox_events')->where('organization_id', $org->id)->where('id', $results[0]['ticket'])->count());
        } finally {
            if ($locked) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(2);
                }
            }
            $this->removeRaceFixture($directory);
        }
    }

    private function raceWorker(string $directory, array $input): Process
    {
        $connection = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'CABINET_ORGANIZATION_ID' => $input['organization'],
            'DB_URL' => '', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'] ?? '',
            'DB_TEST_DATABASE' => config('runtime.test_database'), 'APP_PREVIEW_DATABASE' => config('runtime.preview_database') ?? '',
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'AI_DRIVER' => 'fake', 'DELIVERY_DRIVER' => 'fake',
            'APP_STORAGE_PATH' => $directory.'/storage', 'APP_CONFIG_CACHE' => $directory.'/cache/config.php', 'APP_ROUTES_CACHE' => $directory.'/cache/routes.php',
            'APP_EVENTS_CACHE' => $directory.'/cache/events.php', 'APP_SERVICES_CACHE' => $directory.'/cache/services.php',
            'APP_PACKAGES_CACHE' => $directory.'/cache/packages.php', 'VIEW_COMPILED_PATH' => $directory.'/storage/framework/views'];

        return new Process([PHP_BINARY, '-d', 'sys_temp_dir='.$directory, __DIR__.'/Fixtures/plan-request-race-worker.php'], base_path(), $environment,
            json_encode($input + ['directory' => $directory, 'database' => DB::connection()->getDatabaseName()], JSON_THROW_ON_ERROR), 60);
    }

    private function removeRaceFixture(string $directory): void
    {
        $resolved = realpath($directory);
        $root = realpath(base_path('.local/runtime'));
        $normalize = static fn (string $path): string => strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
        if (! is_string($resolved) || ! is_string($root) || is_link($directory)
            || ! str_starts_with($normalize($resolved), $normalize($root).'/plan-request-race-')) {
            throw new RuntimeException('Refusing to remove an unexpected fixture path.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($resolved);
    }
}
