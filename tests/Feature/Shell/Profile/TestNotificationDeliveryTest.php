<?php

namespace Tests\Feature\Shell\Profile;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Profile\TestNotifications\TestNotifications;
use App\Shell\Profile\TestNotifications\TestNotificationTransport;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class TestNotificationDeliveryTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function test_committed_external_test_is_once_only_uncertain_is_not_retried_and_revoked_access_cancels(): void
    {
        Http::preventStrayRequests();
        $transport = new class extends TestNotificationTransport
        {
            public int $calls = 0;

            public bool $accepted = true;

            public bool $enabled = true;

            public array $recipients = [];

            public function available(string $channel): bool
            {
                return $this->enabled;
            }

            public function send(string $channel, #[\SensitiveParameter] string $destination): bool
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \LogicException('Transport saw open transaction');
                }
                $this->calls++;
                $this->recipients[] = $destination;

                return $this->accepted;
            }
        };
        $this->app->instance(TestNotificationTransport::class, $transport);
        $user = User::create(['name' => 'Test notification', 'email' => 'test-notification@example.test', 'password' => 'test-only-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Notification fixture', 'owner_user_id' => $user->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id), fn () => [Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Notification brand'])]);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        Auth::login($user);
        session()->start();
        session()->put('auth.version', $user->auth_version);
        $request = Request::create('/profile/notifications/test', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $selection = app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->initialize($request, $org->id, $brand->id));
        $request->attributes->set('tenant.brand_revision', (string) $selection->brand_revision);
        $context = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        $notifications = app(TestNotifications::class);
        $enqueue = fn () => app(TenantContextRunner::class)->run($context, fn () => $notifications->request($request, 'email', (string) Str::uuid()));
        $first = $enqueue();
        $notifications->deliver($first);
        $notifications->deliver($first);
        $this->assertSame(1, $transport->calls);
        $this->assertSame([$user->email], $transport->recipients);
        $this->assertDatabaseHas('profile_test_notifications', ['id' => $first, 'status' => 'sent']);
        $uncertain = $enqueue();
        $transport->accepted = false;
        $notifications->deliver($uncertain);
        $notifications->deliver($uncertain);
        $this->assertSame(2, $transport->calls);
        $this->assertDatabaseHas('profile_test_notifications', ['id' => $uncertain, 'status' => 'uncertain']);
        $disabled = $enqueue();
        $transport->enabled = false;
        $notifications->deliver($disabled);
        $this->assertSame(2, $transport->calls);
        $this->assertDatabaseHas('profile_test_notifications', ['id' => $disabled, 'status' => 'cancelled']);
        $transport->enabled = true;
        $revoked = $enqueue();
        DB::table('memberships')->where('id', $member->id)->update(['status' => 'disabled']);
        $notifications->deliver($revoked);
        $this->assertSame(2, $transport->calls);
        $this->assertDatabaseHas('profile_test_notifications', ['id' => $revoked, 'status' => 'cancelled']);
        Http::assertNothingSent();
    }

    public function test_telegram_receipt_requires_ok_true_and_uses_only_the_fake_http_transport(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 200)->push(['ok' => true], 200)]);
        config(['features.E0-04.defaults.external_delivery' => true, 'features.E0-04.defaults.telegram_bot_token' => 'fake-test-token']);
        $transport = app(TestNotificationTransport::class);
        $this->assertFalse($transport->send('telegram', '123456'));
        $this->assertTrue($transport->send('telegram', '123456'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['chat_id'] === '123456' && $request['text'] === 'Тестовое уведомление личного кабинета');
    }
}
