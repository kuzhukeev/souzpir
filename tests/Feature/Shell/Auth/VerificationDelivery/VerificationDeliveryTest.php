<?php

namespace Tests\Feature\Shell\Auth\VerificationDelivery;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\VerificationDelivery\DeliveryClaim;
use App\Shell\Auth\VerificationDelivery\Jobs\DeliverVerification;
use App\Shell\Auth\VerificationDelivery\KnownDeliveryFailure;
use App\Shell\Auth\VerificationDelivery\SmtpVerificationTransport;
use App\Shell\Auth\VerificationDelivery\VerificationDelivery;
use App\Shell\Auth\VerificationDelivery\VerificationTransport;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class VerificationDeliveryTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    private User $user;

    private Organization $org;

    private Membership $membership;

    private Request $request;

    private VerificationDelivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(config('runtime.test_database'), DB::connection()->getDatabaseName());
        self::assertNotSame(config('runtime.preview_database'), DB::connection()->getDatabaseName());
        $this->user = User::create(['name' => 'Synthetic', 'email' => 'verification-'.Str::lower(Str::random(12)).'@example.test', 'password' => 'test-only-password']);
        $this->user->refresh();
        $this->org = Organization::create(['name' => 'Synthetic company', 'owner_user_id' => $this->user->id]);
        config(['features.E0-02.defaults.organization_id' => $this->org->id, 'features.E1-01D.defaults.enabled' => true]);
        $this->membership = app(TenantContextRunner::class)->run(new TenantContext($this->org->id, actorId: $this->user->id, source: 'job'), fn () => Membership::create(['user_id' => $this->user->id, 'role' => 'owner']));
        $session = app('session')->driver('array');
        $session->start();
        $session->put('auth.version', $this->user->auth_version);
        $this->request = Request::create('/verification', 'POST');
        $this->request->setLaravelSession($session);
        $this->request->setUserResolver(fn () => $this->user);
        app(AuthSessionRegistry::class)->issue($this->request, $this->user, $this->user->auth_version);
        $this->delivery = app(VerificationDelivery::class);
    }

    private function enqueue(string $key = 'synthetic-key-000001'): array
    {
        return $this->delivery->enqueue($this->request, $key);
    }

    private function state(string $id): object
    {
        return DB::table('verification_delivery_intents')->where('id', $id)->first();
    }

    public function test_disabled_creates_nothing_and_default_transport_never_sends(): void
    {
        config(['features.E1-01D.defaults.enabled' => false]);
        self::assertSame(['status' => 'disabled', 'id' => null], $this->enqueue());
        self::assertSame(0, DB::table('verification_delivery_intents')->count());
        $this->expectException(KnownDeliveryFailure::class);
        app(VerificationTransport::class)->send($this->user);
    }

    public function test_logout_before_enqueue_prevents_any_intent(): void
    {
        app(AuthSessionRegistry::class)->withinActive($this->request, fn () => app(AuthSessionRegistry::class)->revokeCurrent($this->request));
        try {
            $this->enqueue();
            self::fail('Expected authentication failure');
        } catch (AuthenticationException) {
            self::assertSame(0, DB::table('verification_delivery_intents')->count());
        }
    }

    public function test_multiple_keys_map_to_one_intent_and_replays_never_create_new_delivery(): void
    {
        $one = $this->enqueue();
        $two = $this->enqueue('synthetic-key-000002');
        self::assertSame($one['id'], $two['id']);
        $claim = $this->delivery->claim($one['id']);
        self::assertNotNull($claim);
        $transport = new class implements VerificationTransport
        {
            public int $calls = 0;

            public function send(User $user): bool
            {
                $this->calls++;
                TestCase::assertSame(0, DB::transactionLevel());

                return true;
            }
        };
        $this->delivery->send($claim, $transport);
        $this->delivery->send($claim, $transport);
        self::assertSame(1, $transport->calls);
        self::assertSame('sent', $this->state($one['id'])->status);
        self::assertSame($one['id'], $this->enqueue('synthetic-key-000002')['id']);
        self::assertSame(1, DB::table('verification_delivery_intents')->count());
    }

    public function test_logout_before_claim_and_after_claim_prevents_transport(): void
    {
        $one = $this->enqueue();
        DB::table('auth_session_registry')->update(['revoked_at' => now()]);
        self::assertNull($this->delivery->claim($one['id']));
        self::assertSame('cancelled', $this->state($one['id'])->status);
        DB::table('auth_session_registry')->update(['revoked_at' => null]);
        $two = $this->enqueue('synthetic-key-000003');
        $claim = $this->delivery->claim($two['id']);
        DB::table('auth_session_registry')->update(['revoked_at' => now()]);
        $transport = new class implements VerificationTransport
        {
            public int $calls = 0;

            public function send(User $user): bool
            {
                $this->calls++;

                return true;
            }
        };
        $this->delivery->send($claim, $transport);
        self::assertSame(0, $transport->calls);
        self::assertSame('cancelled', $this->state($two['id'])->status);
    }

    public function test_membership_revocation_and_email_change_cancel_delivery(): void
    {
        $one = $this->enqueue();
        DB::table('memberships')->where('id', $this->membership->id)->update(['status' => 'disabled']);
        self::assertNull($this->delivery->claim($one['id']));
        self::assertSame('cancelled', $this->state($one['id'])->status);
        DB::table('memberships')->where('id', $this->membership->id)->update(['status' => 'active']);
        $two = $this->enqueue('synthetic-key-000003');
        $claim = $this->delivery->claim($two['id']);
        DB::table('users')->where('id', $this->user->id)->update(['email' => 'changed@example.test']);
        $transport = new class implements VerificationTransport
        {
            public int $calls = 0;

            public function send(User $user): bool
            {
                $this->calls++;

                return true;
            }
        };
        $this->delivery->send($claim, $transport);
        self::assertSame(0, $transport->calls);
        self::assertSame('cancelled', $this->state($two['id'])->status);
    }

    public function test_known_failure_uncertain_recovery_and_secrets_are_not_persisted(): void
    {
        $one = $this->enqueue();
        $claim = $this->delivery->claim($one['id']);
        $known = new class implements VerificationTransport
        {
            public function send(User $user): bool
            {
                throw new KnownDeliveryFailure('secret-address-and-token');
            }
        };
        $this->delivery->send($claim, $known);
        self::assertSame('failed', $this->state($one['id'])->status);
        $two = $this->enqueue('synthetic-key-000003');
        $claim2 = $this->delivery->claim($two['id']);
        $unknown = new class implements VerificationTransport
        {
            public function send(User $user): bool
            {
                throw new \RuntimeException('secret-address-and-token');
            }
        };
        $this->delivery->send($claim2, $unknown);
        self::assertSame('uncertain', $this->state($two['id'])->status);
        self::assertNull($this->delivery->claim($two['id']));
        self::assertSame($two['id'], $this->enqueue('synthetic-key-000004')['id']);
        $rows = json_encode([DB::table('verification_delivery_intents')->get(), DB::table('verification_delivery_keys')->get()], JSON_THROW_ON_ERROR);
        foreach ([$this->user->email, $this->request->session()->getId(), 'synthetic-key-000003', 'secret-address-and-token', (new \ReflectionProperty($claim2, 'token'))->getValue($claim2)] as $secret) {
            self::assertStringNotContainsString($secret, $rows);
        }
    }

    public function test_interrupted_worker_is_conservatively_uncertain_without_retry(): void
    {
        $one = $this->enqueue();
        $claim = $this->delivery->claim($one['id']);
        self::assertTrue($this->delivery->markUncertain($one['id']));
        $transport = new class implements VerificationTransport
        {
            public int $calls = 0;

            public function send(User $user): bool
            {
                $this->calls++;

                return true;
            }
        };
        $this->delivery->send($claim, $transport);
        self::assertSame(0, $transport->calls);
        self::assertSame('uncertain', $this->state($one['id'])->status);
        self::assertNull($this->delivery->claim($one['id']));
    }

    public function test_concurrent_requests_share_one_intent_and_only_one_worker_claims(): void
    {
        $sessionId = $this->request->session()->getId();
        $sessionHash = hash_hmac('sha256', 'auth-session:'.$sessionId, config('app.key'));
        $workers = [];
        try {
            DB::beginTransaction();
            DB::table('auth_session_registry')->where('session_hash', $sessionHash)->lockForUpdate()->first();
            foreach (['concurrent-key-000001', 'concurrent-key-000002'] as $key) {
                $input = json_encode(['org' => $this->org->id, 'user' => $this->user->id, 'session' => $sessionId, 'key' => $key], JSON_THROW_ON_ERROR);
                $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/concurrent-worker.php'], base_path(), ['APP_ENV' => 'testing'], $input, 45);
                $process->start();
                $workers[] = $process;
            }
            $pids = [];
            $deadline = microtime(true) + 30;
            while (microtime(true) < $deadline && count($pids) < 2) {
                $pids = [];
                foreach ($workers as $worker) {
                    if (preg_match('/ready:(\d+)/', $worker->getOutput(), $match)) {
                        $pids[] = (int) $match[1];
                    }
                }
                if (count($pids) < 2) {
                    usleep(10000);
                }
            }
            self::assertCount(2, $pids);
            $blocked = 0;
            while (microtime(true) < $deadline && $blocked < 2) {
                $blocked = DB::table('pg_locks')->whereIn('pid', $pids)->where('granted', false)->distinct()->count('pid');
                if ($blocked < 2) {
                    usleep(10000);
                }
            }
            self::assertSame(2, $blocked);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = json_decode(trim(preg_replace('/ready:\d+\s*/', '', $worker->getOutput())), true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertSame($results[0]['id'], $results[1]['id']);
            self::assertSame(1, (int) $results[0]['claimed'] + (int) $results[1]['claimed']);
            self::assertSame(1, DB::table('verification_delivery_intents')->count());
            self::assertSame(2, DB::table('verification_delivery_keys')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_reentrant_send_cannot_transmit_same_claim_twice(): void
    {
        $intent = $this->enqueue();
        $claim = $this->delivery->claim($intent['id']);
        $transport = new class($this->delivery, $claim) implements VerificationTransport
        {
            public int $calls = 0;

            public function __construct(private VerificationDelivery $delivery, private DeliveryClaim $claim) {}

            public function send(User $user): bool
            {
                $this->calls++;
                $this->delivery->send($this->claim, $this);

                return true;
            }
        };
        $this->delivery->send($claim, $transport);
        self::assertSame(1, $transport->calls);
        self::assertSame('sent', $this->state($intent['id'])->status);
    }

    public function test_smtp_adapter_requires_opt_in_and_never_calls_mail_when_disabled(): void
    {
        Mail::shouldReceive('mailer')->never();
        $this->expectException(KnownDeliveryFailure::class);
        app(SmtpVerificationTransport::class)->send($this->user);
    }

    public function test_smtp_acceptance_receipt_and_temporary_signed_url_without_real_network(): void
    {
        config(['features.E1-01D.defaults.smtp_enabled' => true, 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'sender@example.test']);
        $html = null;
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('html')->once()->andReturnUsing(function ($body, $callback) use (&$html) {
            $html = $body;

            return \Mockery::mock(SentMessage::class);
        });
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $intent = $this->enqueue();
        $claim = $this->delivery->claim($intent['id']);
        $this->delivery->send($claim, app(SmtpVerificationTransport::class));
        self::assertSame('sent', $this->state($intent['id'])->status);
        self::assertSame(1, preg_match('/href="([^"]+)"/', $html, $match));
        $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $request = Request::create($url);
        self::assertTrue(URL::hasValidSignature($request));
        self::assertStringContainsString('/email/verify/'.$this->user->id.'/'.sha1($this->user->email), $url);
        self::assertGreaterThan(now()->timestamp, (int) $request->query('expires'));
        self::assertLessThanOrEqual(now()->addMinutes(60)->timestamp, (int) $request->query('expires'));
        self::assertStringNotContainsString($url, json_encode($this->state($intent['id']), JSON_THROW_ON_ERROR));
    }

    public function test_smtp_null_receipt_is_uncertain_and_never_auto_retried(): void
    {
        config(['features.E1-01D.defaults.smtp_enabled' => true, 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'sender@example.test']);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('html')->once()->andReturn(null);
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $intent = $this->enqueue();
        $claim = $this->delivery->claim($intent['id']);
        $this->delivery->send($claim, app(SmtpVerificationTransport::class));
        self::assertSame('uncertain', $this->state($intent['id'])->status);
        self::assertNull($this->delivery->claim($intent['id']));
    }

    public function test_claims_are_redacted_and_cannot_be_serialized_or_sent_inside_a_transaction(): void
    {
        $intent = $this->enqueue();
        $claim = $this->delivery->claim($intent['id']);
        $token = (new \ReflectionProperty($claim, 'token'))->getValue($claim);
        self::assertStringNotContainsString($token, print_r($claim, true));
        self::assertStringNotContainsString($token, json_encode($claim, JSON_THROW_ON_ERROR));
        try {
            serialize($claim);
            self::fail('Claim serialization must be forbidden');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
        $transport = new class implements VerificationTransport
        {
            public int $calls = 0;

            public function send(User $user): bool
            {
                $this->calls++;

                return true;
            }
        };
        DB::beginTransaction();
        try {
            $this->delivery->send($claim, $transport);
            self::fail('Must not send under an outer transaction');
        } catch (\LogicException) {
            self::assertSame(0, $transport->calls);
        } finally {
            DB::rollBack();
        }
    }

    public function test_auth_version_change_and_already_verified_account_cancel(): void
    {
        $one = $this->enqueue();
        DB::table('users')->where('id', $this->user->id)->increment('auth_version');
        self::assertNull($this->delivery->claim($one['id']));
        self::assertSame('cancelled', $this->state($one['id'])->status);
        DB::table('users')->where('id', $this->user->id)->decrement('auth_version');
        $two = $this->enqueue('synthetic-key-000003');
        $claim = $this->delivery->claim($two['id']);
        DB::table('users')->where('id', $this->user->id)->update(['email_verified_at' => now()]);
        $transport = new class implements VerificationTransport
        {
            public int $calls = 0;

            public function send(User $user): bool
            {
                $this->calls++;

                return true;
            }
        };
        $this->delivery->send($claim, $transport);
        self::assertSame(0, $transport->calls);
        self::assertSame('cancelled', $this->state($two['id'])->status);
    }

    public function test_job_only_contains_opaque_id_and_is_safe_to_invoke_twice(): void
    {
        $intent = $this->enqueue();
        $job = new DeliverVerification($intent['id']);
        $serialized = serialize($job);
        self::assertStringNotContainsString($this->user->email, $serialized);
        self::assertStringNotContainsString($this->request->session()->getId(), $serialized);
        $transport = new class implements VerificationTransport
        {
            public int $calls = 0;

            public function send(User $user): bool
            {
                $this->calls++;

                return true;
            }
        };
        $job->handle($this->delivery, $transport);
        $job->handle($this->delivery, $transport);
        self::assertSame(1, $transport->calls);
        self::assertSame('sent', $this->state($intent['id'])->status);
    }

    public function test_invited_unverified_user_loses_delivery_permission_when_invite_revoked(): void
    {
        $invited = User::create(['name' => 'Invited', 'email' => 'invited@example.test', 'password' => 'test-only-password'])->fresh();
        $invitation = (string) Str::ulid();
        DB::table('invitations')->insert(['id' => $invitation, 'organization_id' => $this->org->id, 'email' => $invited->email, 'role' => 'member', 'token_hash' => hash('sha256', 'synthetic-invite'), 'invited_by' => $this->user->id, 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $session = new Store('invite-test', new ArraySessionHandler(120));
        $session->start();
        $session->put('auth.version', $invited->auth_version);
        $request = Request::create('/verification', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $invited);
        app(AuthSessionRegistry::class)->issue($request, $invited, $invited->auth_version);
        $intent = $this->delivery->enqueue($request, 'synthetic-invite-key');
        DB::table('invitations')->where('id', $invitation)->update(['revoked_at' => now()]);
        self::assertNull($this->delivery->claim($intent['id']));
        self::assertSame('cancelled', $this->state($intent['id'])->status);
    }

    public function test_concurrent_revocation_wins_before_both_claim_and_send(): void
    {
        foreach (['claim', 'send'] as $phase) {
            foreach (['membership', 'organization', 'invitation'] as $kind) {
                $invitation = null;
                if ($kind === 'invitation') {
                    DB::table('memberships')->where('id', $this->membership->id)->update(['status' => 'disabled']);
                    $invitation = (string) Str::ulid();
                    DB::table('invitations')->insert(['id' => $invitation, 'organization_id' => $this->org->id, 'email' => $this->user->email, 'role' => 'member', 'token_hash' => hash('sha256', $invitation), 'invited_by' => $this->user->id, 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
                }
                $intent = $this->enqueue('revocation-'.$phase.'-'.$kind.'-01');
                $input = new InputStream;
                $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/revocation-worker.php', $this->org->id, $intent['id'], $phase], base_path(), ['APP_ENV' => 'testing'], $input, 45);
                try {
                    $worker->start();
                    $deadline = microtime(true) + 30;
                    while (microtime(true) < $deadline && ! preg_match('/ready:(\d+)/', $worker->getOutput(), $match)) {
                        usleep(10000);
                    }
                    self::assertNotEmpty($match[1] ?? null);
                    DB::beginTransaction();
                    if ($kind === 'membership') {
                        DB::table('memberships')->where('id', $this->membership->id)->update(['status' => 'disabled']);
                    }
                    if ($kind === 'organization') {
                        DB::table('organizations')->where('id', $this->org->id)->update(['status' => 'disabled']);
                    }
                    if ($kind === 'invitation') {
                        DB::table('invitations')->where('id', $invitation)->update(['revoked_at' => now()]);
                    }
                    $input->write("go\n");
                    $blocked = false;
                    while (microtime(true) < $deadline && ! $blocked) {
                        $worker->isRunning();
                        $blocked = DB::table('pg_locks')->where('pid', (int) $match[1])->where('granted', false)->exists();
                        if (! $blocked) {
                            usleep(10000);
                        }
                    }
                    self::assertTrue($blocked, 'Worker must wait for '.$kind.' revocation');
                    DB::commit();
                    $worker->wait();
                    self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                    $result = json_decode(trim(preg_replace('/ready:\d+\s*/', '', $worker->getOutput())), true, 512, JSON_THROW_ON_ERROR);
                    self::assertSame(['calls' => 0, 'status' => 'cancelled'], $result);
                } finally {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    if ($worker->isRunning()) {
                        $worker->stop();
                    }
                    $input->close();
                    DB::table('memberships')->where('id', $this->membership->id)->update(['status' => 'active']);
                    DB::table('organizations')->where('id', $this->org->id)->update(['status' => 'active']);
                    if ($invitation) {
                        DB::table('invitations')->where('id', $invitation)->update(['revoked_at' => now()]);
                    }
                }
            }
        }
    }

    public function test_concurrent_access_revocation_prevents_new_intent(): void
    {
        $input = json_encode(['org' => $this->org->id, 'user' => $this->user->id, 'session' => $this->request->session()->getId(), 'key' => 'concurrent-revocation-key'], JSON_THROW_ON_ERROR);
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/concurrent-worker.php'], base_path(), ['APP_ENV' => 'testing'], $input, 45);
        try {
            DB::beginTransaction();
            DB::table('memberships')->where('id', $this->membership->id)->update(['status' => 'disabled']);
            $worker->start();
            $deadline = microtime(true) + 30;
            while (microtime(true) < $deadline && ! preg_match('/ready:(\d+)/', $worker->getOutput(), $match)) {
                usleep(10000);
            }
            self::assertNotEmpty($match[1] ?? null);
            $blocked = false;
            while (microtime(true) < $deadline && ! $blocked) {
                $blocked = DB::table('pg_locks')->where('pid', (int) $match[1])->where('granted', false)->exists();
                if (! $blocked) {
                    usleep(10000);
                }
            }
            self::assertTrue($blocked);
            DB::commit();
            $worker->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertSame('denied', trim(preg_replace('/ready:\d+\s*/', '', $worker->getOutput())));
            self::assertSame(0, DB::table('verification_delivery_intents')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
}
