<?php

namespace Tests\Feature\Shell\Auth\PasswordDelivery;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\PasswordDelivery\Jobs\DeliverPassword;
use App\Shell\Auth\PasswordDelivery\PasswordDelivery;
use App\Shell\Auth\PasswordDelivery\PasswordTransport;
use App\Shell\Auth\PasswordDelivery\SmtpPasswordTransport;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PasswordDeliveryTest extends TestCase
{
    use DatabaseMigrations;

    private User $user;

    private PasswordDelivery $delivery;

    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('souzpir_test_sp_e1_01f', DB::connection()->getDatabaseName());
        self::assertSame(config('runtime.test_database'), DB::connection()->getDatabaseName());
        self::assertNotSame(config('runtime.preview_database'), DB::connection()->getDatabaseName());
        $this->withoutVite();
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
        self::assertTrue(Hash::check($this->fake->token, $row->token));
        self::assertStringNotContainsString($this->fake->token, json_encode($row));
        self::assertStringNotContainsString($this->user->email, json_encode(DB::table('password_delivery_intents')->first()));
        DB::table('sessions')->insert(['id' => 'synthetic-session', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);
        $oldVersion = $this->user->auth_version;
        $data = ['email' => $this->user->email, 'token' => $this->fake->token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];
        $this->post('/reset-password', $data)->assertRedirect(route('login'));
        self::assertTrue(Hash::check('new-secure-password', $this->user->fresh()->password));
        self::assertSame($oldVersion + 1, $this->user->fresh()->auth_version);
        self::assertFalse(DB::table('sessions')->where('id', 'synthetic-session')->exists());
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
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
        $this->post('/reset-password', ['email' => $this->user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasErrors('email');
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
        self::assertStringContainsString('/reset-password/', $html);
        self::assertStringContainsString('email=recovery%40example.test', $html);
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
        $this->post('/reset-password', ['email' => $this->user->email, 'token' => $old, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertSessionHasErrors('email');
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
}
