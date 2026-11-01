<?php

namespace Tests\Feature\Shell\MemberActivity;

use App\Shell\Auth\Totp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class CompletedLoginTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_only_completed_mfa_login_is_recorded_and_activity_is_not_a_login(): void
    {
        [$owner] = $this->cabinet();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $owner->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => now(), 'mfa_last_counter' => null])->save();
        $this->freezeSecond();
        $valid = app(Totp::class)->at($secret, intdiv(now()->timestamp, 30));
        $invalid = $valid === '000000' ? '111111' : '000000';
        $this->post('/login', ['email' => $owner->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('member_last_logins', ['user_id' => $owner->id]);
        $this->post('/login', ['email' => $owner->email, 'password' => 'test-only-password', 'remember' => true])->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
        $this->assertDatabaseMissing('member_last_logins', ['user_id' => $owner->id]);
        $this->post('/mfa/challenge', ['code' => $invalid])->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('member_last_logins', ['user_id' => $owner->id]);
        $response = $this->post('/mfa/challenge', ['code' => $valid])->assertRedirect(route('auth.ready'));
        $this->assertAuthenticatedAs($owner);
        $this->assertTrue(collect($response->headers->getCookies())->contains(fn ($cookie) => $cookie->getName() === auth()->guard()->getRecallerName()));
        $recorded = DB::table('member_last_logins')->where('user_id', $owner->id)->first();
        $this->assertSame(['user_id', 'last_login_at'], array_keys((array) $recorded));
        $this->assertTrue(CarbonImmutable::parse($recorded->last_login_at)->equalTo(now()));
        $this->travel(1)->minutes();
        $this->withCookie(config('session.cookie'), session()->getId())->get('/team')->assertOk();
        $this->assertSame($recorded->last_login_at, DB::table('member_last_logins')->where('user_id', $owner->id)->value('last_login_at'));
        $this->withCookie(config('session.cookie'), session()->getId())->post('/logout')->assertRedirect(route('login'));
        $this->withCookie(config('session.cookie'), session()->getId())->post('/login', ['email' => $owner->email, 'password' => 'test-only-password'])->assertRedirect(route('mfa.challenge'));
        $code = app(Totp::class)->at($secret, intdiv(now()->timestamp, 30));
        $this->post('/mfa/challenge', ['code' => $code])->assertRedirect(route('auth.ready'));
        $this->assertTrue(CarbonImmutable::parse(DB::table('member_last_logins')->where('user_id', $owner->id)->value('last_login_at'))->equalTo(now()));
        $this->assertSame(1, DB::table('member_last_logins')->where('user_id', $owner->id)->count());
        Http::assertNothingSent();
    }

    public function test_login_succeeds_without_the_optional_table(): void
    {
        [$owner] = $this->cabinet();
        Schema::drop('member_last_logins');
        $this->post('/login', ['email' => $owner->email, 'password' => 'test-only-password'])->assertRedirect(route('auth.ready'));
        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseHas('auth_session_registry', ['user_id' => $owner->id]);
        $this->assertSame(1, DB::selectOne('select 1 as ok')->ok);
        Http::assertNothingSent();
    }

    public function test_login_succeeds_when_the_optional_write_fails(): void
    {
        [$owner] = $this->cabinet();
        DB::unprepared("CREATE FUNCTION e106d_test_fail_login_record() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'synthetic write failure'; END; \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER e106d_test_fail_login_record BEFORE INSERT OR UPDATE ON member_last_logins FOR EACH ROW EXECUTE FUNCTION e106d_test_fail_login_record()');
        $this->post('/login', ['email' => $owner->email, 'password' => 'test-only-password'])->assertRedirect(route('auth.ready'));
        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseHas('auth_session_registry', ['user_id' => $owner->id]);
        $this->assertDatabaseMissing('member_last_logins', ['user_id' => $owner->id]);
        Http::assertNothingSent();
    }
}
