<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\VerificationDelivery\Jobs\DeliverVerification;
use App\Shell\Auth\VerificationDelivery\VerificationTransport;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class VerificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        Event::fake([Registered::class]);
        Queue::fake();
        config(['features.E1-01D.defaults.enabled' => true]);
        app()->instance(VerificationTransport::class, new class implements VerificationTransport
        {
            public function send(User $user): bool
            {
                throw new \RuntimeException('The test must not perform transport.');
            }
        });
    }

    public function test_owner_registration_creates_intent_only_after_registered_session(): void
    {
        $org = Organization::create(['name' => 'Cabinet']);
        $secret = str_repeat('b', 40);
        config(['features.E0-02.defaults.organization_id' => $org->id, 'features.E1-01.defaults.bootstrap_token_hash' => hash('sha256', $secret)]);
        $this->post('/register', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secure-password-example',
            'password_confirmation' => 'secure-password-example', 'terms' => true, 'ai_consent' => true, 'bootstrap_code' => $secret])
            ->assertRedirect(route('verification.notice'))->assertSessionHas('status', 'Запрос на отправку принят. Проверьте почту позже.');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/email/verify')->assertOk()->assertInertia(fn ($page) => $page->has('resendAfter'));
        self::assertGreaterThan(now()->timestamp, session('verification_resend_after'));
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $intent = DB::table('verification_delivery_intents')->where('user_id', $user->id)->first();
        self::assertNotNull($intent);
        self::assertSame('pending', $intent->status);
        self::assertSame(hash_hmac('sha256', 'auth-session:'.session()->getId(), (string) config('app.key')), $intent->session_hash);
        $this->assertDatabaseHas('auth_session_registry', ['user_id' => $user->id, 'session_hash' => $intent->session_hash]);
        Queue::assertPushed(DeliverVerification::class, fn ($job) => $job->intentId === $intent->id && $job->queue === config('runtime.queues.notifications'));
        Event::assertNotDispatched(Registered::class);
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_resend_is_idempotent_uncertain_is_not_retried_and_redirect_is_local(): void
    {
        $org = Organization::create(['name' => 'Cabinet']);
        $secret = str_repeat('c', 40);
        config(['features.E0-02.defaults.organization_id' => $org->id, 'features.E1-01.defaults.bootstrap_token_hash' => hash('sha256', $secret)]);
        $this->post('/register', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secure-password-example',
            'password_confirmation' => 'secure-password-example', 'terms' => true, 'ai_consent' => true, 'bootstrap_code' => $secret])->assertRedirect();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withHeader('Referer', 'https://outside.example/path');
        $key = str_repeat('a', 32);
        $this->post('/email/verification-notification', ['idempotency_key' => $key])->assertRedirect(route('verification.notice'))->assertSessionHas('status', 'Запрос на отправку принят. Проверьте почту позже.');
        $this->post('/email/verification-notification', ['idempotency_key' => $key])->assertRedirect(route('verification.notice'));
        self::assertSame(1, DB::table('verification_delivery_intents')->count());
        self::assertSame(2, DB::table('verification_delivery_keys')->count()); // registration key plus one resend
        DB::table('verification_delivery_intents')->update(['status' => 'uncertain']);
        $this->post('/email/verification-notification', ['idempotency_key' => str_repeat('d', 32)])->assertRedirect(route('verification.notice'))->assertSessionHas('status', 'Результат отправки неизвестен. Обратитесь к администратору.');
        self::assertSame(1, DB::table('verification_delivery_intents')->count());
        Queue::assertPushed(DeliverVerification::class, 3); // no job for uncertain
        $this->post('/email/verification-notification', ['idempotency_key' => 'short'])->assertRedirect(route('verification.notice'));
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_invited_registration_uses_registered_session_without_direct_notifications(): void
    {
        $org = Organization::create(['name' => 'Cabinet']);
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secure-password-example']);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $token = str_repeat('z', 64);
        $invitation = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $owner->id), fn () => Invitation::create([
            'email' => 'member@example.test', 'role' => 'member', 'brand_ids' => [], 'token_hash' => hash('sha256', $token),
            'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
        ]));
        $page = $this->get('/invitations/open')->assertOk();
        self::assertSame(1, preg_match('/epoch:"([a-f0-9]{64})"/', $page->getContent(), $match));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials()->postJson('/invitations/exchange', ['token' => $token, 'epoch' => $match[1], 'sequence' => 1])->assertOk();
        $this->post('/invitations/register', ['name' => 'Member', 'password' => 'another-secure-password',
            'password_confirmation' => 'another-secure-password', 'terms' => true])
            ->assertRedirect(route('verification.notice'))->assertSessionHas('status', 'Запрос на отправку принят. Проверьте почту позже.');
        $member = User::where('email', 'member@example.test')->firstOrFail();
        $this->assertDatabaseHas('verification_delivery_intents', ['user_id' => $member->id, 'status' => 'pending']);
        $this->assertDatabaseHas('auth_session_registry', ['user_id' => $member->id, 'revoked_at' => null]);
        self::assertNull($invitation->fresh()->accepted_at);
        Queue::assertPushed(DeliverVerification::class, 1);
        Event::assertNotDispatched(Registered::class);
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_disabled_transport_does_not_create_intent_and_verified_user_goes_forward(): void
    {
        $org = Organization::create(['name' => 'Cabinet']);
        $secret = str_repeat('e', 40);
        config(['features.E0-02.defaults.organization_id' => $org->id, 'features.E1-01.defaults.bootstrap_token_hash' => hash('sha256', $secret),
            'features.E1-01.defaults.verification_enabled' => true, 'features.E1-01D.defaults.enabled' => false]);
        $this->post('/register', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secure-password-example',
            'password_confirmation' => 'secure-password-example', 'terms' => true, 'ai_consent' => true, 'bootstrap_code' => $secret])->assertRedirect();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/email/verification-notification', ['idempotency_key' => str_repeat('f', 32)])
            ->assertRedirect(route('verification.notice'))->assertSessionHas('status', 'Почтовый сервис пока не подключён.');
        self::assertSame(0, DB::table('verification_delivery_intents')->count());
        Queue::assertNothingPushed();
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->post('/email/verification-notification', ['idempotency_key' => str_repeat('f', 32)])->assertRedirect(route('auth.ready'));
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_scanner_never_dispatches_uncertain_intents(): void
    {
        $org = Organization::create(['name' => 'Cabinet']);
        $secret = str_repeat('d', 40);
        config(['features.E0-02.defaults.organization_id' => $org->id, 'features.E1-01.defaults.bootstrap_token_hash' => hash('sha256', $secret)]);
        $this->post('/register', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secure-password-example',
            'password_confirmation' => 'secure-password-example', 'terms' => true, 'ai_consent' => true, 'bootstrap_code' => $secret])->assertRedirect();
        $this->artisan('auth:verification-dispatch-pending', ['--limit' => 10])->assertSuccessful();
        Queue::assertPushed(DeliverVerification::class, 2);
        DB::table('verification_delivery_intents')->update(['status' => 'uncertain']);
        $this->artisan('auth:verification-dispatch-pending', ['--limit' => 10])->assertSuccessful();
        Queue::assertPushed(DeliverVerification::class, 2);
        $this->artisan('auth:verification-dispatch-pending', ['--limit' => 501])->assertFailed();
    }
}
