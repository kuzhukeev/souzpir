<?php

namespace Tests\Feature\Shell\Auth\PasswordDelivery;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\PasswordDelivery\Jobs\DeliverPassword;
use App\Shell\Auth\PasswordDelivery\PasswordDelivery;
use App\Shell\Auth\PasswordDelivery\PasswordTransport;
use App\Shell\Auth\PasswordDelivery\SmtpPasswordTransport;
use App\Shell\Auth\Totp;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class PasswordDeliveryTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    private User $user;

    private PasswordDelivery $delivery;

    private object $fake;

    protected function beforeRefreshingDatabase(): void
    {
        $expected = basename(dirname(base_path())) === '.worktrees' ? 'souzpir_test_'.str_replace('-', '_', basename(base_path())) : 'souzpir_test_main';
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $expected
            || DB::connection()->getDatabaseName() !== config('runtime.test_database')
            || DB::connection()->getDatabaseName() === config('runtime.preview_database')) {
            throw new \LogicException('Isolated test database required.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $expected = basename(dirname(base_path())) === '.worktrees' ? 'souzpir_test_'.str_replace('-', '_', basename(base_path())) : 'souzpir_test_main';
        self::assertSame($expected, DB::connection()->getDatabaseName());
        self::assertSame(config('runtime.test_database'), DB::connection()->getDatabaseName());
        self::assertNotSame(config('runtime.preview_database'), DB::connection()->getDatabaseName());
        $this->withoutVite();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        Mail::fake();
        Queue::fake();
        config(['features.E1-01F.defaults.enabled' => true, 'queue.default' => 'database']);
        $this->user = User::create(['name' => 'Synthetic', 'email' => 'recovery@example.test', 'password' => 'old-password-example']);
        $this->user->refresh();
        $org = Organization::create(['name' => 'Synthetic', 'owner_user_id' => $this->user->id]);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $this->user->id, source: 'job'), fn () => Membership::create(['user_id' => $this->user->id, 'role' => 'owner']));
        $this->fake = new class implements PasswordTransport
        {
            public int $calls = 0;

            public ?string $token = null;

            public bool $accepted = true;

            public bool $throw = false;

            public function send(#[\SensitiveParameter] User $user, #[\SensitiveParameter] string $token): bool
            {
                TestCase::assertSame(0, DB::transactionLevel());
                $this->calls++;
                $this->token = $token;
                if ($this->throw) {
                    throw new \RuntimeException('address='.$user->email.' token='.$token);
                }

                return $this->accepted;
            }
        };
        app()->instance(PasswordTransport::class, $this->fake);
        $this->delivery = app(PasswordDelivery::class);
    }

    private function sendIntent(): string
    {
        $this->post('/forgot-password', ['email' => $this->user->email])->assertRedirect()->assertSessionHas('status', 'Если адрес зарегистрирован, инструкции будут отправлены.');

        return DB::table('password_delivery_intents')->value('id');
    }

    private function resetThroughExchange(array $data): TestResponse
    {
        $this->get('/reset-password/open');
        $this->postJson('/reset-password/exchange', ['token' => $data['token'], 'email' => $data['email']]);

        return $this->post('/reset-password', ['password' => $data['password'], 'password_confirmation' => $data['password_confirmation']]);
    }

    public function test_equal_response_throttle_queue_and_single_use_reset_revokes_sessions(): void
    {
        $unknown = $this->post('/forgot-password', ['email' => 'missing@example.test']);
        $unknown->assertRedirect()->assertSessionHas('status', 'Если адрес зарегистрирован, инструкции будут отправлены.');
        self::assertSame(0, DB::table('password_delivery_intents')->count());
        $id = $this->sendIntent();
        $this->post('/forgot-password', ['email' => $this->user->email])->assertSessionHas('status', 'Если адрес зарегистрирован, инструкции будут отправлены.');
        self::assertSame(1, DB::table('password_delivery_intents')->count());
        Queue::assertPushed(DeliverPassword::class, 1);
        $job = Queue::pushed(DeliverPassword::class)->first();
        self::assertStringNotContainsString($this->user->email, serialize($job));
        $job->handle($this->delivery, $this->fake);
        $job->handle($this->delivery, $this->fake);
        self::assertSame(1, $this->fake->calls);
        self::assertSame('sent', DB::table('password_delivery_intents')->where('id', $id)->value('status'));
        $row = DB::table('password_reset_tokens')->first();
        self::assertTrue(Hash::check('v2:'.hash('sha256', $this->fake->token), $row->token));
        self::assertStringNotContainsString($this->fake->token, json_encode($row));
        self::assertStringNotContainsString($this->user->email, json_encode(DB::table('password_delivery_intents')->first()));
        DB::table('sessions')->insert(['id' => 'synthetic-session', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);
        $oldVersion = $this->user->auth_version;
        $data = ['email' => $this->user->email, 'token' => $this->fake->token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];
        $this->resetThroughExchange($data)->assertRedirect(route('login'));
        self::assertTrue(Hash::check('new-secure-password', $this->user->fresh()->password));
        self::assertSame($oldVersion + 1, $this->user->fresh()->auth_version);
        self::assertFalse(DB::table('sessions')->where('id', 'synthetic-session')->exists());
        $this->resetThroughExchange($data)->assertSessionHasErrors('email');
    }

    public function test_expired_token_revoked_eligibility_and_stale_pending_fail_closed(): void
    {
        $id = $this->sendIntent();
        $this->travel(61)->minutes();
        self::assertNull($this->delivery->claim($id));
        self::assertSame('cancelled', DB::table('password_delivery_intents')->where('id', $id)->value('status'));
        $id = $this->delivery->enqueue($this->user->email);
        $claim = $this->delivery->claim($id);
        DB::table('memberships')->where('user_id', $this->user->id)->update(['status' => 'disabled']);
        $this->delivery->send($claim, $this->fake);
        self::assertSame(0, $this->fake->calls);
        self::assertSame('cancelled', DB::table('password_delivery_intents')->where('id', $id)->value('status'));
        DB::table('memberships')->where('user_id', $this->user->id)->update(['status' => 'active']);
        $this->travel(61)->seconds();
        $id = $this->delivery->enqueue($this->user->email);
        $this->delivery->send($this->delivery->claim($id), $this->fake);
        $token = $this->fake->token;
        $this->travel(61)->minutes();
        $this->resetThroughExchange(['email' => $this->user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasErrors('email');
    }

    public function test_uncertain_never_retries_and_does_not_log_exception_with_credentials(): void
    {
        $id = $this->sendIntent();
        $this->fake->throw = true;
        $job = Queue::pushed(DeliverPassword::class)->first();
        $job->handle($this->delivery, $this->fake);
        self::assertSame('uncertain', DB::table('password_delivery_intents')->where('id', $id)->value('status'));
        self::assertNull($this->delivery->claim($id));
        $this->artisan('auth:password-dispatch-pending', ['--limit' => 100])->assertSuccessful();
        Queue::assertPushed(DeliverPassword::class, 1);
        $row = json_encode(DB::table('password_delivery_intents')->where('id', $id)->first());
        self::assertStringNotContainsString($this->user->email, $row);
        self::assertStringNotContainsString($this->fake->token, $row);
        self::assertStringNotContainsString('address=', $row);
        $this->travel(61)->seconds();
        $new = $this->delivery->enqueue($this->user->email);
        self::assertNotSame($id, $new);
    }

    public function test_smtp_adapter_is_opt_in_and_uses_only_fake_mailer(): void
    {
        config(['features.E1-01F.defaults.smtp_enabled' => true, 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'sender@example.test']);
        URL::forceRootUrl('https://cabinet.example.test');
        URL::forceScheme('https');
        $mailer = \Mockery::mock(Mailer::class);
        $html = '';
        $mailer->shouldReceive('html')->once()->andReturnUsing(function ($body, $callback) use (&$html) {
            $html = $body;

            return \Mockery::mock(SentMessage::class);
        });
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $id = $this->delivery->enqueue($this->user->email);
        $this->delivery->send($this->delivery->claim($id), app(SmtpPasswordTransport::class));
        self::assertSame('sent', DB::table('password_delivery_intents')->where('id', $id)->value('status'));
        self::assertStringContainsString('/reset-password/open#token=', $html);
        self::assertStringContainsString('email=recovery%40example.test', $html);
        preg_match('/href="([^"]+)"/', $html, $match);
        $url = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        self::assertSame('/reset-password/open', parse_url($url, PHP_URL_PATH));
        self::assertNull(parse_url($url, PHP_URL_QUERY));
        parse_str(parse_url($url, PHP_URL_FRAGMENT), $fragment);
        self::assertSame($this->user->email, $fragment['email']);
        self::assertTrue(Hash::check('v2:'.hash('sha256', $fragment['token']), DB::table('password_reset_tokens')->value('token')));
    }

    public function test_previously_issued_token_with_current_version_is_rejected(): void
    {
        $legacy = bin2hex(random_bytes(32));
        DB::table('password_reset_tokens')->insert(['email' => $this->user->email, 'token' => Hash::make($legacy), 'auth_version' => $this->user->auth_version, 'created_at' => now()]);
        $this->resetThroughExchange(['email' => $this->user->email, 'token' => $legacy, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasErrors('email');
        self::assertTrue(Hash::check('old-password-example', $this->user->fresh()->password));
        self::assertTrue(DB::table('password_reset_tokens')->where('email', $this->user->email)->exists());
    }

    public function test_smtp_never_sends_an_insecure_nonlocal_link(): void
    {
        config(['features.E1-01F.defaults.smtp_enabled' => true, 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'sender@example.test']);
        URL::forceRootUrl('http://cabinet.example.test');
        URL::forceScheme('http');
        Mail::shouldReceive('mailer')->never();
        $id = $this->delivery->enqueue($this->user->email);
        $this->delivery->send($this->delivery->claim($id), app(SmtpPasswordTransport::class));
        self::assertSame('failed', DB::table('password_delivery_intents')->where('id', $id)->value('status'));
    }

    public function test_fragment_exchange_and_password_only_post_are_single_use_and_revoke_sessions(): void
    {
        $id = $this->sendIntent();
        $this->delivery->send($this->delivery->claim($id), $this->fake);
        $token = $this->fake->token;
        $email = $this->user->email;
        $this->get('/reset-password/open')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/reset-password/exchange', compact('token', 'email'))->assertOk()->assertExactJson(['ok' => true])->assertHeader('Cache-Control', 'no-store, private');
        $grant = session('auth.password_recovery');
        self::assertIsArray($grant);
        self::assertSame(hash('sha256', $token), $grant['hash']);
        self::assertStringNotContainsString($token, json_encode($grant));
        self::assertStringNotContainsString($email, json_encode($grant));
        $this->get('/reset-password')->assertOk()->assertDontSee($token)->assertDontSee($email);
        DB::table('sessions')->insert(['id' => 'recovery-test-session', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);
        $password = ['password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];
        $this->post('/reset-password', $password)->assertRedirect(route('login'));
        self::assertTrue(Hash::check($password['password'], $this->user->fresh()->password));
        self::assertFalse(DB::table('sessions')->where('id', 'recovery-test-session')->exists());
        self::assertNull(session('auth.password_recovery'));
        $this->postJson('/reset-password/exchange', compact('token', 'email'))->assertOk();
        $this->post('/reset-password', $password)->assertSessionHasErrors('email');
    }

    public function test_concurrent_requests_create_only_one_intent(): void
    {
        $streams = [new InputStream, new InputStream];
        $workers = [];
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $this->user->id)->lockForUpdate()->first();
            foreach ($streams as $stream) {
                $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/concurrent-worker.php'], base_path(), ['APP_ENV' => 'testing'], $stream, 45);
                $process->start();
                $stream->write(json_encode(['user' => $this->user->id, 'org' => config('features.E0-02.defaults.organization_id')], JSON_THROW_ON_ERROR)."\n");
                $workers[] = $process;
            }
            $pids = [];
            foreach ($workers as $worker) {
                $deadline = microtime(true) + 30;
                while (microtime(true) < $deadline && ! preg_match('/ready:(\d+)/', $worker->getOutput(), $match)) {
                    usleep(10000);
                }
                self::assertNotEmpty($match[1] ?? null);
                $pids[] = (int) $match[1];
            }
            foreach ($streams as $stream) {
                $stream->write("go\n");
            }
            $deadline = microtime(true) + 20;
            do {
                foreach ($workers as $worker) {
                    $worker->isRunning();
                }
                $waiting = DB::table('pg_locks')->whereIn('pid', $pids)->where('granted', false)->distinct()->count('pid');
                if ($waiting < 2) {
                    usleep(10000);
                }
            } while ($waiting < 2 && microtime(true) < $deadline);
            self::assertSame(2, $waiting);
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = trim(preg_replace('/ready:\d+\s*/', '', $worker->getOutput()));
            }
            sort($results);
            self::assertSame(['enqueued', 'skipped'], $results);
            self::assertSame(1, DB::table('password_delivery_intents')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ($streams as $stream) {
                $stream->close();
            }
        }
    }

    public function test_failed_preparation_exception_and_trace_do_not_leak_credentials(): void
    {
        $id = $this->delivery->enqueue($this->user->email);
        $claim = $this->delivery->claim($id);
        Hash::shouldReceive('make')->once()->andThrow(new \RuntimeException('sensitive '.$this->user->email.' marker-token'));
        try {
            $this->delivery->send($claim, $this->fake);
            self::fail('Expected safe failure');
        } catch (\RuntimeException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($this->user->email, (string) $exception);
            self::assertStringNotContainsString('marker-token', (string) $exception);
            $frames = array_slice($exception->getTrace(), 0, 2);
            foreach ($frames as $frame) {
                foreach ($frame['args'] ?? [] as $arg) {
                    if (is_string($arg)) {
                        self::assertStringNotContainsString($this->user->email, $arg);
                        self::assertStringNotContainsString('marker-token', $arg);
                    }
                }
            }
            self::assertSame(0, $this->fake->calls);
        }
    }

    public function test_user_requested_replacement_invalidates_old_link_and_interrupted_send_is_not_retried(): void
    {
        $first = $this->sendIntent();
        $this->delivery->send($this->delivery->claim($first), $this->fake);
        $old = $this->fake->token;
        $this->travel(61)->seconds();
        $second = $this->delivery->enqueue($this->user->email);
        $this->delivery->send($this->delivery->claim($second), $this->fake);
        self::assertNotSame($old, $this->fake->token);
        $this->resetThroughExchange(['email' => $this->user->email, 'token' => $old, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasErrors('email');
        $this->travel(61)->seconds();
        $third = $this->delivery->enqueue($this->user->email);
        $claim = $this->delivery->claim($third);
        self::assertNotNull($claim);
        self::assertTrue($this->delivery->markUncertain($third));
        $this->delivery->send($claim, $this->fake);
        self::assertSame(2, $this->fake->calls);
        self::assertSame('uncertain', DB::table('password_delivery_intents')->where('id', $third)->value('status'));
        self::assertNull($this->delivery->claim($third));
    }

    public function test_disabled_and_legacy_flag_never_send_or_create_intent(): void
    {
        config(['features.E1-01F.defaults.enabled' => false, 'features.E1-01.defaults.verification_enabled' => true]);
        $this->post('/forgot-password', ['email' => $this->user->email])->assertRedirect(route('password.request'))->assertSessionHas('status', 'Если адрес зарегистрирован, инструкции будут отправлены.');
        self::assertSame(0, DB::table('password_delivery_intents')->count());
        self::assertSame(0, $this->fake->calls);
        Queue::assertNothingPushed();
    }

    public function test_issued_and_legacy_tokens_fail_closed_after_version_change_or_disabled_organization(): void
    {
        $id = $this->sendIntent();
        $this->delivery->send($this->delivery->claim($id), $this->fake);
        $data = ['email' => $this->user->email, 'token' => $this->fake->token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];
        $org = config('features.E0-02.defaults.organization_id');
        DB::table('organizations')->where('id', $org)->update(['status' => 'disabled']);
        $this->resetThroughExchange($data)->assertSessionHasErrors('email');
        self::assertTrue(Hash::check('old-password-example', $this->user->fresh()->password));
        DB::table('organizations')->where('id', $org)->update(['status' => 'active']);
        DB::table('users')->where('id', $this->user->id)->increment('auth_version');
        $this->resetThroughExchange($data)->assertSessionHasErrors('email');
        self::assertTrue(Hash::check('old-password-example', $this->user->fresh()->password));
        $legacy = app('auth.password.broker')->createToken($this->user);
        $this->resetThroughExchange([...$data, 'token' => $legacy])->assertSessionHasErrors('email');
        self::assertNull(DB::table('password_reset_tokens')->where('email', $this->user->email)->value('auth_version'));
    }

    public function test_enabling_mfa_invalidates_already_issued_reset_link(): void
    {
        $this->user->forceFill(['email_verified_at' => now()])->save();
        $id = $this->sendIntent();
        $this->delivery->send($this->delivery->claim($id), $this->fake);
        $token = $this->fake->token;
        $this->post('/login', ['email' => $this->user->email, 'password' => 'old-password-example'])->assertRedirect();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('https://localhost/mfa/setup', ['password' => 'old-password-example'])->assertRedirect(route('mfa.setup'));
        $secret = session('auth.mfa_setup.secret');
        $code = app(Totp::class)->at($secret, intdiv(now()->timestamp, 30));
        $this->post('/mfa/setup/confirm', ['code' => $code])->assertRedirect(route('auth.ready'));
        self::assertSame(1, $this->user->fresh()->auth_version);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->resetThroughExchange(['email' => $this->user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasErrors('email');
        self::assertTrue(Hash::check('old-password-example', $this->user->fresh()->password));
    }

    public function test_reset_and_revocation_serialize_in_both_orders(): void
    {
        $id = $this->sendIntent();
        $this->delivery->send($this->delivery->claim($id), $this->fake);
        $token = $this->fake->token;
        [$worker, $input, $pid] = $this->raceWorker('revoke');
        try {
            DB::beginTransaction();
            $this->resetThroughExchange(['email' => $this->user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertRedirect(route('login'));
            $input->write("go\n");
            $this->waitForLock($worker, $pid);
            DB::commit();
            $worker->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertStringContainsString('revoked', $worker->getOutput());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $input->close();
        }
        DB::table('memberships')->where('user_id', $this->user->id)->update(['status' => 'active']);
        $this->travel(61)->seconds();
        $id = $this->delivery->enqueue($this->user->email);
        $this->delivery->send($this->delivery->claim($id), $this->fake);
        [$worker, $input, $pid] = $this->raceWorker('reset', ['token' => $this->fake->token]);
        try {
            DB::beginTransaction();
            DB::table('organizations')->where('id', config('features.E0-02.defaults.organization_id'))->lockForUpdate()->first();
            DB::table('memberships')->where('user_id', $this->user->id)->update(['status' => 'disabled']);
            $input->write("go\n");
            $this->waitForLock($worker, $pid);
            DB::commit();
            $worker->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertStringContainsString('denied', $worker->getOutput());
            self::assertSame(1, $this->user->fresh()->auth_version);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $input->close();
        }
    }

    public function test_competing_claims_and_sends_are_once_only_and_revocation_cancels_send(): void
    {
        $id = $this->sendIntent();
        [$first, $inFirst, $pidFirst] = $this->raceWorker('claim', ['id' => $id]);
        [$second, $inSecond, $pidSecond] = $this->raceWorker('claim', ['id' => $id]);
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $this->user->id)->lockForUpdate()->first();
            $inFirst->write("go\n");
            $inSecond->write("go\n");
            $this->waitForLock($first, $pidFirst);
            $this->waitForLock($second, $pidSecond);
            DB::commit();
            $first->wait();
            $second->wait();
            self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $output = $first->getOutput().' '.$second->getOutput();
            self::assertSame(1, substr_count($output, 'claimed'));
            self::assertSame(1, substr_count($output, 'skipped'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($first->isRunning()) {
                $first->stop();
            }
            if ($second->isRunning()) {
                $second->stop();
            }
            $inFirst->close();
            $inSecond->close();
        }
        // The first workers deliberately do not leak the claim secret; use a fresh real claim.
        self::assertTrue($this->delivery->markUncertain($id));
        $this->travel(61)->seconds();
        $id = $this->delivery->enqueue($this->user->email);
        $claim = $this->delivery->claim($id);
        $secret = (new \ReflectionProperty($claim, 'token'))->getValue($claim);
        [$first, $inFirst, $pidFirst] = $this->raceWorker('send', ['id' => $id, 'claim' => $secret]);
        [$second, $inSecond, $pidSecond] = $this->raceWorker('send', ['id' => $id, 'claim' => $secret]);
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $this->user->id)->lockForUpdate()->first();
            $inFirst->write("go\n");
            $inSecond->write("go\n");
            $this->waitForLock($first, $pidFirst);
            $this->waitForLock($second, $pidSecond);
            DB::commit();
            $first->wait();
            $second->wait();
            self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $output = $first->getOutput().' '.$second->getOutput();
            self::assertSame(1, substr_count($output, 'calls:1'));
            self::assertSame(1, substr_count($output, 'calls:0'));
            self::assertSame('sent', DB::table('password_delivery_intents')->where('id', $id)->value('status'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($first->isRunning()) {
                $first->stop();
            } if ($second->isRunning()) {
                $second->stop();
            }
            $inFirst->close();
            $inSecond->close();
        }
        $this->travel(61)->seconds();
        $id = $this->delivery->enqueue($this->user->email);
        $claim = $this->delivery->claim($id);
        $secret = (new \ReflectionProperty($claim, 'token'))->getValue($claim);
        [$worker, $input, $pid] = $this->raceWorker('send', ['id' => $id, 'claim' => $secret]);
        try {
            DB::beginTransaction();
            DB::table('organizations')->where('id', config('features.E0-02.defaults.organization_id'))->lockForUpdate()->first();
            DB::table('memberships')->where('user_id', $this->user->id)->update(['status' => 'disabled']);
            $input->write("go\n");
            $this->waitForLock($worker, $pid);
            DB::commit();
            $worker->wait();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertStringContainsString('calls:0', $worker->getOutput());
            self::assertSame('cancelled', DB::table('password_delivery_intents')->where('id', $id)->value('status'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $input->close();
        }
    }

    public function test_revocation_after_transmission_claim_does_not_retry_or_cancel_inflight_send(): void
    {
        $id = $this->sendIntent();
        $claim = $this->delivery->claim($id);
        $secret = (new \ReflectionProperty($claim, 'token'))->getValue($claim);
        [$sender, $sendInput] = $this->raceWorker('send', ['id' => $id, 'claim' => $secret, 'pause' => true]);
        [$revoker, $revokeInput] = $this->raceWorker('revoke');
        try {
            $sendInput->write("go\n");
            $deadline = microtime(true) + 20;
            do {
                $sender->isRunning();
                if (str_contains($sender->getOutput(), 'transmitting')) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertStringContainsString('transmitting', $sender->getOutput());
            self::assertSame('transmitting', DB::table('password_delivery_intents')->where('id', $id)->value('status'));
            $revokeInput->write("go\n");
            $revoker->wait();
            self::assertSame(0, $revoker->getExitCode(), $revoker->getErrorOutput());
            self::assertStringContainsString('revoked', $revoker->getOutput());
            $sendInput->write("go\n");
            $sender->wait();
            self::assertSame(0, $sender->getExitCode(), $sender->getErrorOutput());
            self::assertStringContainsString('calls:1', $sender->getOutput());
            self::assertSame('sent', DB::table('password_delivery_intents')->where('id', $id)->value('status'));
            self::assertNull($this->delivery->claim($id));
        } finally {
            if ($sender->isRunning()) {
                $sender->stop();
            }
            if ($revoker->isRunning()) {
                $revoker->stop();
            }
            $sendInput->close();
            $revokeInput->close();
        }
    }

    private function raceWorker(string $mode, array $extra = []): array
    {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.base_path('.local/tmp'), __DIR__.'/Fixtures/eligibility-worker.php'], base_path(), ['APP_ENV' => 'testing'], $input, 45);
        $worker->start();
        $input->write(json_encode(['mode' => $mode, 'user' => $this->user->id, 'org' => config('features.E0-02.defaults.organization_id'), ...$extra], JSON_THROW_ON_ERROR)."\n");
        $deadline = microtime(true) + 30;
        do {
            $worker->isRunning();
            if (preg_match('/ready:(\d+)/', $worker->getOutput(), $matches)) {
                return [$worker, $input, (int) $matches[1]];
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $worker->stop();
        $input->close();
        self::fail('Worker not ready: '.$worker->getErrorOutput());
    }

    private function waitForLock(Process $worker, int $pid): void
    {
        $deadline = microtime(true) + 20;
        do {
            $worker->isRunning();
            if (DB::table('pg_locks')->where('pid', $pid)->where('granted', false)->exists()) {
                self::assertTrue(true);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Worker did not wait for lock: '.$worker->getErrorOutput());
    }
}
