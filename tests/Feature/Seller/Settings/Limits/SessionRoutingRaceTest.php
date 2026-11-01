<?php

namespace Tests\Feature\Seller\Settings\Limits;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Settings\Limits\SellerNotificationRouter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Notifications\DomainEvent;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('race')]
final class SessionRoutingRaceTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $expected = basename(dirname(base_path())) === '.worktrees' ? 'souzpir_test_'.str_replace('-', '_', basename(base_path())) : 'souzpir_test_main';
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $expected || $expected !== config('runtime.test_database') || $expected === config('runtime.preview_database')) {
            throw new \LogicException('Isolated test database required.');
        }
    }

    private function fixture(): array
    {
        Http::preventStrayRequests();
        Http::fake();
        $user = User::create(['name' => 'Synthetic', 'email' => 'race-'.Str::lower(Str::random(12)).'@example.test', 'password' => 'synthetic-original-password']);
        $user->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $user->id]);
        config(['features.E0-02.defaults.organization_id' => $org->id, 'features.E6-07.defaults.digest_enabled' => true]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Synthetic brand', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $eventType = 'seller.conversation.handed_off';
        DB::table('seller_notification_rules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'event_key' => $eventType, 'recipient' => 'owner', 'cabinet' => true, 'email' => false, 'telegram' => false, 'timing' => 'digest', 'created_at' => now(), 'updated_at' => now()]);
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => $eventType, 'occurred_at' => now()->toIso8601String(),
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => null,
            'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1],
            'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        DB::table('outbox_events')->insert(['id' => $event->envelope['event_id'], 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($event->envelope, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        $session = app('session')->driver('array');
        $session->setId(Str::random(40));
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $request = Request::create('/', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);

        return compact('user', 'org', 'member', 'brand', 'event', 'request', 'session');
    }

    public static function mutations(): array
    {
        return [
            'rules' => ['rules', ['changes' => [['event' => 'seller.conversation.handed_off', 'recipient' => 'owner', 'channels' => ['cabinet' => true, 'email' => false, 'telegram' => false], 'timing' => 'digest']]]],
            'time' => ['time', ['digest_time' => '10:45']],
            'test' => ['test', ['event' => 'seller.conversation.handed_off', 'channel' => 'cabinet', 'key' => '11111111-1111-4111-8111-111111111111']],
        ];
    }

    #[DataProvider('mutations')]
    public function test_real_mutation_and_router_do_not_deadlock(string $action, array $body): void
    {
        $f = $this->fixture();
        $data = $this->workerData($f, $action, $body);
        $process = null;
        $stream = null;
        try {
            DB::beginTransaction();
            DB::statement("SET LOCAL statement_timeout = '7s'");
            DB::table('organizations')->where('id', $f['org']->id)->lockForUpdate()->first();
            [$process, $stream, $pid] = $this->startWorker($data);
            $stream->write("go\n");
            $this->waitUntilBlocked($process, $pid);
            self::assertSame('digest', app(SellerNotificationRouter::class)->route($f['event'], $f['member'], 'cabinet'));
            DB::commit();
            $this->finishWorker($process, $stream);
            $process = null;
            self::assertSame(1, DB::table('seller_digest_items')->where('event_id', $f['event']->envelope['event_id'])->count());
            match ($action) {
                'rules' => self::assertSame('digest', DB::table('seller_notification_rules')->where('brand_id', $f['brand']->id)->value('timing')),
                'time' => self::assertSame('10:45', DB::table('seller_digest_preferences')->where('user_id', $f['user']->id)->value('digest_time')),
                'test' => self::assertSame(1, DB::table('seller_test_deliveries')->where('user_id', $f['user']->id)->count()),
            };
            Http::assertNothingSent();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($process !== null && $process->isRunning()) {
                $process->stop();
            }
            if ($stream !== null) {
                $stream->close();
            }
        }
    }

    public function test_password_reset_waits_for_active_action_then_invalidates_old_version(): void
    {
        $f = $this->fixture();
        $token = 'synthetic-reset-token';
        DB::table('password_reset_tokens')->insert(['email' => $f['user']->email, 'token' => Hash::make($token), 'auth_version' => $f['user']->auth_version, 'created_at' => now()]);
        $data = $this->workerData($f, 'password', []);
        $data['token'] = $token;
        $process = null;
        $stream = null;
        try {
            app(AuthSessionRegistry::class)->withinActive($f['request'], function () use ($data, &$process, &$stream, $f): void {
                [$process, $stream, $pid] = $this->startWorker($data);
                $stream->write("go\n");
                $this->waitUntilBlocked($process, $pid);
                self::assertSame(0, $f['user']->fresh()->auth_version);
                DB::table('seller_digest_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $f['org']->id, 'brand_id' => $f['brand']->id, 'user_id' => $f['user']->id, 'digest_time' => '10:45', 'created_at' => now(), 'updated_at' => now()]);
            });
            $this->finishWorker($process, $stream);
            $process = null;
            self::assertSame(1, $f['user']->fresh()->auth_version);
            self::assertTrue(Hash::check('synthetic-new-password', $f['user']->fresh()->password));
            try {
                app(AuthSessionRegistry::class)->withinActive($f['request'], fn () => self::fail('Stale version was accepted.'));
                self::fail('Password reset must invalidate the prior session.');
            } catch (AuthenticationException) {
                self::assertTrue(true);
            }
            Http::assertNothingSent();
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->stop();
            }
            if ($stream !== null) {
                $stream->close();
            }
        }
    }

    public function test_concurrent_version_change_first_denies_the_old_session_action(): void
    {
        $f = $this->fixture();
        $data = $this->workerData($f, 'time', ['digest_time' => '10:45']);
        $process = null;
        $stream = null;
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $f['user']->id)->lockForUpdate()->first();
            DB::table('users')->where('id', $f['user']->id)->update(['auth_version' => DB::raw('auth_version + 1')]);
            [$process, $stream, $pid] = $this->startWorker($data);
            $stream->write("go\n");
            $this->waitUntilBlocked($process, $pid);
            DB::commit();
            $this->finishWorker($process, $stream, 'denied');
            $process = null;
            self::assertSame(1, $f['user']->fresh()->auth_version);
            self::assertSame(0, DB::table('seller_digest_preferences')->where('user_id', $f['user']->id)->count());
            Http::assertNothingSent();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($process !== null && $process->isRunning()) {
                $process->stop();
            }
            if ($stream !== null) {
                $stream->close();
            }
        }
    }

    private function workerData(array $f, string $action, array $body): array
    {
        return ['user' => $f['user']->id, 'organization' => $f['org']->id, 'brand' => $f['brand']->id, 'membership' => $f['member']->id,
            'session' => $f['session']->getId(), 'version' => $f['user']->auth_version, 'action' => $action, 'body' => $body];
    }

    private function startWorker(array $data): array
    {
        $stream = new InputStream;
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/session-routing-worker.php'], base_path(), array_merge(getenv(), ['APP_ENV' => 'testing']), $stream, 45);
        $process->start();
        $stream->write(json_encode($data, JSON_THROW_ON_ERROR)."\n");
        $deadline = microtime(true) + 30;
        do {
            $process->isRunning();
            if (preg_match('/ready:(\d+)/', $process->getOutput(), $match)) {
                return [$process, $stream, (int) $match[1]];
            }
            usleep(10000);
        } while ($process->isRunning() && microtime(true) < $deadline);
        self::fail('Worker did not start: '.$process->getOutput().$process->getErrorOutput());
    }

    private function waitUntilBlocked(Process $process, int $pid): void
    {
        $deadline = microtime(true) + 20;
        do {
            $process->isRunning();
            if (DB::table('pg_locks')->where('pid', $pid)->where('granted', false)->exists()) {
                self::assertTrue(true);

                return;
            }
            usleep(10000);
        } while ($process->isRunning() && microtime(true) < $deadline);
        self::fail('Expected lock wait not observed: '.$process->getOutput().$process->getErrorOutput());
    }

    private function finishWorker(Process $process, InputStream $stream, string $expected = 'ok'): void
    {
        $stream->close();
        $process->wait();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame($expected, trim(preg_replace('/ready:\d+\s*/', '', $process->getOutput())));
    }
}
