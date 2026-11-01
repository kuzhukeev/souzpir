<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\VerificationDelivery\Jobs\DeliverVerification;
use App\Shell\Auth\VerificationDelivery\VerificationDelivery;
use App\Shell\Auth\VerificationDelivery\VerificationTransport;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class VerificationConcurrencyIntegrationTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function test_registration_intent_reaches_only_fake_transport_after_commit(): void
    {
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $transport = new class implements VerificationTransport
        {
            public int $calls = 0;

            public function send(User $user): bool
            {
                TestCase::assertSame(0, DB::transactionLevel());
                $this->calls++;

                return true;
            }
        };
        app()->instance(VerificationTransport::class, $transport);
        $org = Organization::create(['name' => 'Cabinet']);
        $secret = str_repeat('s', 40);
        config(['features.E0-02.defaults.organization_id' => $org->id, 'features.E1-01D.defaults.enabled' => true,
            'features.E1-01.defaults.bootstrap_token_hash' => hash('sha256', $secret)]);
        $this->post('/register', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secure-password-example',
            'password_confirmation' => 'secure-password-example', 'terms' => true, 'ai_consent' => true, 'bootstrap_code' => $secret])
            ->assertRedirect(route('verification.notice'));
        $job = Queue::pushed(DeliverVerification::class)->first();
        self::assertNotNull($job);
        self::assertSame('pending', DB::table('verification_delivery_intents')->where('id', $job->intentId)->value('status'));
        $job->handle(app(VerificationDelivery::class), $transport);
        $job->handle(app(VerificationDelivery::class), $transport);
        self::assertSame(1, $transport->calls);
        self::assertSame('sent', DB::table('verification_delivery_intents')->where('id', $job->intentId)->value('status'));
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_acceptance_and_delivery_lock_user_before_organization_and_finish_without_deadlock(): void
    {
        self::assertSame(config('runtime.test_database'), DB::connection()->getDatabaseName());
        self::assertNotSame(config('runtime.preview_database'), DB::connection()->getDatabaseName());
        $user = User::create(['name' => 'Test', 'email' => 'race@example.test', 'password' => 'test-only-password'])->fresh();
        $org = Organization::create(['name' => 'Cabinet', 'owner_user_id' => $user->id]);
        config(['features.E0-02.defaults.organization_id' => $org->id, 'features.E1-01D.defaults.enabled' => true]);
        app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id, source: 'job'), fn () => Membership::create(['user_id' => $user->id, 'role' => 'owner']));
        $token = str_repeat('r', 64);
        DB::table('invitations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'email' => $user->email,
            'role' => 'member', 'token_hash' => hash('sha256', $token), 'invited_by' => $user->id, 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $session = app('session')->driver('array');
        $session->start();
        $session->put('auth.version', $user->auth_version);
        $request = Request::create('/verification', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $intent = app(VerificationDelivery::class)->enqueue($request, 'concurrent-accept-001');
        DB::table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);
        $acceptInput = new InputStream;
        $deliveryInput = new InputStream;
        $accept = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/acceptance-worker.php', $org->id, $user->id, $token], base_path(), ['APP_ENV' => 'testing'], $acceptInput, 45);
        $delivery = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/VerificationDelivery/Fixtures/revocation-worker.php', $org->id, $intent['id'], 'claim'], base_path(), ['APP_ENV' => 'testing'], $deliveryInput, 45);
        try {
            $accept->start();
            $delivery->start();
            $deadline = microtime(true) + 30;
            do {
                $readyA = preg_match('/ready:(\d+)/', $accept->getOutput(), $a);
                $readyD = preg_match('/ready:(\d+)/', $delivery->getOutput(), $d);
                if ($readyA && $readyD) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertNotEmpty($a[1] ?? null);
            self::assertNotEmpty($d[1] ?? null);
            DB::beginTransaction();
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $acceptInput->write("go\n");
            $deliveryInput->write("go\n");
            do {
                $accept->isRunning();
                $delivery->isRunning();
                $blocked = DB::table('pg_locks')->whereIn('pid', [(int) $a[1], (int) $d[1]])->where('granted', false)->distinct()->count('pid');
                if ($blocked === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, 'Both paths must wait on the locked user before organization.');
            DB::statement("SET LOCAL lock_timeout = '2s'");
            self::assertNotNull(DB::table('organizations')->where('id', $org->id)->lockForUpdate()->first());
            DB::commit();
            $accept->wait();
            $delivery->wait();
            self::assertSame(0, $accept->getExitCode(), $accept->getErrorOutput());
            self::assertSame(0, $delivery->getExitCode(), $delivery->getErrorOutput());
            self::assertSame('owner', Membership::withoutGlobalScopes()->where('user_id', $user->id)->value('role'));
            self::assertSame('cancelled', DB::table('verification_delivery_intents')->where('id', $intent['id'])->value('status'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($accept->isRunning()) {
                $accept->stop();
            }
            if ($delivery->isRunning()) {
                $delivery->stop();
            }
            $acceptInput->close();
            $deliveryInput->close();
        }
    }
}
