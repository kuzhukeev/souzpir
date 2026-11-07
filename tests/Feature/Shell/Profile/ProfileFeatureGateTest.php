<?php

namespace Tests\Feature\Shell\Profile;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Auth\MfaChallenge;
use App\Shell\Auth\MfaCode;
use App\Shell\Auth\Totp;
use App\Shell\Profile\DeliverEmailChange;
use App\Shell\Profile\EmailChanges;
use App\Shell\Profile\ProfileSettingsAvailability;
use App\Shell\Profile\TestNotifications\DeliverTestNotification;
use App\Shell\Profile\TestNotifications\TestNotifications;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\IsolatedDatabaseMigrations;
use Tests\Support\MfaTestDatabase;
use Tests\TestCase;

final class ProfileFeatureGateTest extends TestCase
{
    use IsolatedDatabaseMigrations;

    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        Mail::fake();
        Http::preventStrayRequests();
    }

    public function test_disabled_feature_and_absent_tables_preserve_password_and_totp_login(): void
    {
        config(['features.E1-08.defaults.enabled' => false]);
        foreach (['profile_test_notifications', 'profile_session_details', 'profile_telegram_links', 'profile_telegram_accounts', 'profile_email_changes', 'profile_preferences', 'mfa_recovery_codes', 'mfa_challenge_limits'] as $table) {
            Schema::dropIfExists($table);
        }
        [$user] = $this->actor();
        $this->post('/login', ['email' => $user->email, 'password' => 'fixture-password-only'])->assertRedirect(route('auth.ready'));
        $this->assertAuthenticatedAs($user);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/profile')->assertNotFound();
        $this->get('/settings/notifications')->assertNotFound();
        $this->post('/profile/notifications/test', [])->assertNotFound();
        $this->post('/profile/telegram', [])->assertNotFound();
        $this->post('/logout')->assertRedirect(route('login'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $user->forceFill(['mfa_secret' => self::SECRET, 'mfa_confirmed_at' => now()])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'fixture-password-only'])->assertRedirect(route('mfa.challenge'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/mfa/challenge')->assertOk()->assertInertia(fn (Assert $page) => $page->where('recoveryAvailable', false));
        $this->post('https://souzpir.local/mfa/challenge', ['code' => 'AAAAAAAA-BBBBBBBB-CCCCCCCC-DDDDDDDD'])->assertSessionHasErrors('code');
        $code = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        $this->post('/mfa/challenge', ['code' => $code])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_profile_is_available_by_default_when_schema_is_ready(): void
    {
        self::assertTrue(config('features.E1-08.defaults.enabled'));
        [$user, , $brand] = $this->actor();
        $this->post('/login', ['email' => $user->email, 'password' => 'fixture-password-only'])->assertRedirect(route('auth.ready'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withSession(['brand_id' => $brand->id])->get('/profile')->assertOk();
        $this->get('/profile/notifications')->assertOk();
        $this->get('/profile/security')->assertOk();
    }

    public function test_enabled_but_incomplete_schema_keeps_totp_and_hides_recovery(): void
    {
        config(['features.E1-08.defaults.enabled' => true]);
        Schema::table('profile_session_details', fn (Blueprint $table) => $table->dropColumn('ip_address'));
        self::assertFalse(app(ProfileSettingsAvailability::class)->ready());
        [$user] = $this->actor();
        $user->forceFill(['mfa_secret' => self::SECRET, 'mfa_confirmed_at' => now()])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'fixture-password-only'])->assertRedirect(route('mfa.challenge'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/mfa/challenge')->assertOk()->assertInertia(fn (Assert $page) => $page->where('recoveryAvailable', false));
        $code = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        $this->post('/mfa/challenge', ['code' => $code])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/profile')->assertStatus(503);
        self::assertSame(0, DB::table('profile_session_details')->count());
    }

    public function test_shared_limit_survives_schema_and_flag_transition(): void
    {
        [$user] = $this->actor();
        $user->forceFill(['mfa_secret' => self::SECRET, 'mfa_confirmed_at' => now()])->save();
        $user->refresh();
        $pending = ['user_id' => $user->id, 'version' => $user->auth_version, 'expires' => now()->addMinutes(5)->timestamp];
        $wrong = MfaCode::parse('AAAAAAAA-BBBBBBBB-CCCCCCCC-DDDDDDDD');
        config(['features.E1-08.defaults.enabled' => false]);
        Schema::drop('mfa_challenge_limits');
        for ($i = 0; $i < 3; $i++) {
            self::assertNull(app(MfaChallenge::class)->verify($pending, $wrong));
        }
        Schema::create('mfa_challenge_limits', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('failures');
            $table->timestampTz('expires_at');
        });
        config(['features.E1-08.defaults.enabled' => true]);
        self::assertTrue(app(ProfileSettingsAvailability::class)->ready());
        for ($i = 0; $i < 2; $i++) {
            self::assertNull(app(MfaChallenge::class)->verify($pending, $wrong));
        }
        $valid = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        self::assertNull(app(MfaChallenge::class)->verify($pending, MfaCode::parse($valid)));
        self::assertSame(2, DB::table('mfa_challenge_limits')->where('user_id', $user->id)->value('failures'));
    }

    public function test_migration_record_restoration_enables_profile_without_false_email_or_digest(): void
    {
        config(['features.E1-08.defaults.enabled' => true, 'features.E1-08.defaults.email_change_enabled' => false]);
        $name = '2026_10_31_190108_e1_08_create_profile_test_notifications';
        $record = DB::table('migrations')->where('migration', $name)->first();
        self::assertNotNull($record);
        DB::table('migrations')->where('migration', $name)->delete();
        self::assertFalse(app(ProfileSettingsAvailability::class)->ready());
        [$user] = $this->actor();
        $this->post('/login', ['email' => $user->email, 'password' => 'fixture-password-only'])->assertRedirect(route('auth.ready'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->get('/profile')->assertStatus(503);
        self::assertSame(0, DB::table('profile_session_details')->count());
        DB::table('migrations')->insert((array) $record);
        self::assertTrue(app(ProfileSettingsAvailability::class)->ready());
        $this->get('/profile')->assertOk()->assertInertia(fn (Assert $page) => $page->where('profile.email_change_available', false));
        $this->get('/profile/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->where('notificationSettings.timingAvailable', false));
        $this->post('/logout')->assertRedirect(route('login'));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/login', ['email' => $user->email, 'password' => 'fixture-password-only'])->assertRedirect(route('auth.ready'));
        self::assertSame(1, DB::table('profile_session_details')->count());
    }

    public function test_totp_survives_limiter_table_disappearing_after_readiness_check(): void
    {
        config(['features.E1-08.defaults.enabled' => true]);
        [$user] = $this->actor();
        $user->forceFill(['mfa_secret' => self::SECRET, 'mfa_confirmed_at' => now()])->save();
        $user->refresh();
        $pending = ['user_id' => $user->id, 'version' => $user->auth_version, 'expires' => now()->addMinutes(5)->timestamp];
        $drop = true;
        DB::listen(function (QueryExecuted $query) use (&$drop): void {
            if ($drop && str_contains($query->sql, '"users"') && str_contains(strtolower($query->sql), 'for update')) {
                $drop = false;
                Schema::drop('mfa_challenge_limits');
            }
        });
        $code = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        self::assertNotNull(app(MfaChallenge::class)->verify($pending, MfaCode::parse($code)));
        self::assertFalse($drop);
        // The first transaction rolled back, including the synthetic DDL.
        self::assertTrue(Schema::hasTable('mfa_challenge_limits'));
    }

    public function test_queued_delivery_is_blocked_before_reading_missing_profile_tables(): void
    {
        config(['features.E1-08.defaults.enabled' => false]);
        Schema::dropIfExists('profile_email_changes');
        Schema::dropIfExists('profile_test_notifications');
        self::assertFalse(app(EmailChanges::class)->available());
        foreach ([fn () => (new DeliverEmailChange('synthetic'))->handle(app(EmailChanges::class)), fn () => (new DeliverTestNotification('synthetic'))->handle(app(TestNotifications::class))] as $deliver) {
            try {
                $deliver();
                self::fail('Disabled delivery must not be marked successful.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('disabled or not ready', $error->getMessage());
            }
        }
        $this->artisan('profile:dispatch-email-changes')->assertSuccessful();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    private function actor(): array
    {
        $user = User::create(['name' => 'Synthetic', 'email' => 'profile-gate@example.test', 'password' => 'fixture-password-only']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $org = Organization::create(['name' => 'Synthetic org', 'owner_user_id' => $user->id]);
        $brand = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), function () use ($user): Brand {
            Membership::create(['user_id' => $user->id, 'role' => 'owner']);

            return Brand::create(['name' => 'Synthetic brand', 'theme' => 'neutral']);
        });
        config(['features.E0-02.defaults.organization_id' => $org->id]);

        return [$user, $org, $brand];
    }
}
