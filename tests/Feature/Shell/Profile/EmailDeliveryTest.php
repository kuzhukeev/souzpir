<?php

namespace Tests\Feature\Shell\Profile;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Profile\EmailChanges;
use App\Shell\Profile\EmailChangeSender;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\TestCase;

final class EmailDeliveryTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    public function test_committed_delivery_is_once_only_and_cancel_reissue_and_revocation_are_enforced(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['features.E1-08.defaults.email_change_enabled' => true, 'app.url' => 'https://cabinet.example.test']);
        $sender = new class implements EmailChangeSender
        {
            public int $calls = 0;

            public bool $accepted = true;

            public array $urls = [];

            public function send(string $email, string $confirmationUrl): bool
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \LogicException('Transport observed an open transaction.');
                }
                $this->calls++;
                $this->urls[] = $confirmationUrl;

                return $this->accepted;
            }
        };
        $this->app->instance(EmailChangeSender::class, $sender);
        $user = User::create(['name' => 'Email fixture', 'email' => 'email-delivery@example.test', 'password' => 'test-only-password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->refresh();
        $org = Organization::create(['name' => 'Email fixture org', 'owner_user_id' => $user->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id), fn () => [Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Email fixture brand'])]);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        Auth::login($user);
        session()->start();
        session()->put('auth.version', $user->auth_version);
        $request = Request::create('/profile/email', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        $selection = app(AuthSessionRegistry::class)->withinActive($request, fn () => app(SessionBrandSelection::class)->initialize($request, $org->id, $brand->id));
        $request->attributes->set('tenant.brand_revision', (string) $selection->brand_revision);
        $context = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        $changes = app(EmailChanges::class);
        $enqueue = function (string $email) use ($context, $changes, $request, $user): string {
            app(TenantContextRunner::class)->run($context, fn () => $changes->request($request, $email, 'test-only-password'));

            return DB::table('profile_email_changes')->where('user_id', $user->id)->value('id');
        };
        $first = $enqueue('first@example.test');
        $changes->deliver($first);
        $changes->deliver($first);
        $this->assertSame(1, $sender->calls);
        $this->assertDatabaseHas('profile_email_changes', ['id' => $first, 'status' => 'sent', 'encrypted_token' => null]);
        $this->assertStringContainsString('/profile/email/confirm/', $sender->urls[0]);
        $this->assertStringContainsString('signature=', $sender->urls[0]);

        $uncertain = $enqueue('uncertain@example.test');
        $sender->accepted = false;
        $changes->deliver($uncertain);
        $changes->deliver($uncertain);
        $this->assertSame(2, $sender->calls);
        $this->assertDatabaseHas('profile_email_changes', ['id' => $uncertain, 'status' => 'uncertain']);

        $cancelled = $enqueue('cancelled@example.test');
        app(TenantContextRunner::class)->run($context, fn () => $changes->cancel($request));
        $changes->deliver($cancelled);
        $this->assertSame(2, $sender->calls);

        $replaced = $enqueue('replaced@example.test');
        $current = $enqueue('current@example.test');
        $changes->deliver($replaced);
        $this->assertSame(2, $sender->calls);
        DB::table('memberships')->where('id', $member->id)->update(['status' => 'disabled']);
        $changes->deliver($current);
        $this->assertSame(2, $sender->calls);
        $this->assertDatabaseHas('profile_email_changes', ['id' => $current, 'status' => 'cancelled', 'target_email' => null, 'token_hash' => null]);
        Http::assertNothingSent();
    }
}
