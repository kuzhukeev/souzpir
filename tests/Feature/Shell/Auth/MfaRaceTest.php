<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\Http\MfaSettingsController;
use App\Shell\Auth\Totp;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MfaRaceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private function actor(bool $mfa = false): User
    {
        $org = Organization::create(['name' => 'Race fixture']);
        config(['features.E0-02.defaults.organization_id' => $org->id]);
        $user = User::create(['name' => 'Owner', 'email' => 'race@example.test', 'password' => 'original-password']);
        $user->forceFill(['email_verified_at' => now(), 'mfa_secret' => $mfa ? self::SECRET : null, 'mfa_confirmed_at' => $mfa ? now() : null])->save();
        app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => Membership::create(['user_id' => $user->id, 'role' => 'owner']));
        $user->refresh();
        $this->actingAs($user);
        session()->put('auth.version', $user->auth_version);
        $request = Request::create('/mfa/test', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);

        return $user;
    }

    private function request(User $user, array $payload): Request
    {
        $request = Request::create('/mfa/test', 'POST', $payload);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function code(): string
    {
        return app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
    }

    public function test_start_rechecks_version_after_middleware(): void
    {
        $user = $this->actor();
        $request = $this->request($user, ['password' => 'original-password']);
        // Deterministic interleaving: reset commits after middleware saw the stale
        // actor/session, and before the controller locks and rereads the DB row.
        DB::table('users')->where('id', $user->id)->increment('auth_version');
        try {
            app(MfaSettingsController::class)->start($request, app(Totp::class));
            self::fail('Stale session started MFA');
        } catch (AuthenticationException) {
            self::assertNull(session('auth.mfa_setup'));
        }
    }

    public function test_confirm_rechecks_version_after_middleware(): void
    {
        $user = $this->actor();
        session()->put('auth.mfa_setup', ['user_id' => $user->id, 'version' => $user->auth_version, 'secret' => self::SECRET, 'expires' => now()->addMinute()->timestamp]);
        $request = $this->request($user, ['code' => $this->code()]);
        DB::table('users')->where('id', $user->id)->increment('auth_version');
        try {
            app(MfaSettingsController::class)->confirm($request, app(Totp::class));
            self::fail('Stale session enabled MFA');
        } catch (AuthenticationException) {
            self::assertNull($user->fresh()->mfa_confirmed_at);
            self::assertSame(1, $user->fresh()->auth_version);
        }
    }

    public function test_disable_rechecks_version_after_middleware(): void
    {
        $user = $this->actor(true);
        $request = $this->request($user, ['password' => 'original-password', 'code' => $this->code()]);
        DB::table('users')->where('id', $user->id)->increment('auth_version');
        try {
            app(MfaSettingsController::class)->disable($request, app(Totp::class));
            self::fail('Stale session disabled MFA');
        } catch (AuthenticationException) {
            self::assertNotNull($user->fresh()->mfa_confirmed_at);
            self::assertSame(1, $user->fresh()->auth_version);
        }
    }

    public function test_start_and_disable_check_fresh_password_under_user_lock(): void
    {
        $user = $this->actor(true);
        $oldPassword = 'original-password';
        $request = $this->request($user, ['password' => $oldPassword, 'code' => $this->code()]);
        // Keep the in-memory auth object stale to model the password changing
        // between current_password validation and the locked user reread.
        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('replacement-password')]);
        try {
            app(MfaSettingsController::class)->disable($request, app(Totp::class));
            self::fail('Stale password disabled MFA');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('password', $error->errors());
            self::assertNotNull($user->fresh()->mfa_confirmed_at);
        }
        try {
            app(MfaSettingsController::class)->start($request, app(Totp::class));
            self::fail('Stale password started MFA');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('password', $error->errors());
            self::assertNull(session('auth.mfa_setup'));
        }
    }
}
