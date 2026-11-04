<?php

namespace Tests\Feature\Shell\Profile;

use App\Shell\Auth\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class MfaRecoveryE108FTest extends TenancyTestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    public function test_recovery_code_can_be_used_once_and_requires_valid_mfa(): void
    {
        Http::preventStrayRequests();
        $this->withServerVariables(['SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1']);
        [$user, , $brand] = $this->cabinet();
        $user->forceFill(['mfa_secret' => self::SECRET, 'mfa_confirmed_at' => now()])->save();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->postJson('https://souzpir.local/profile/security/mfa/recovery', ['code' => 'invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('mfa_recovery_codes', 0);
        $totp = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        $codes = $this->postJson('https://souzpir.local/profile/security/mfa/recovery', ['code' => $totp])->assertOk()->json('codes');
        self::assertCount(10, $codes);
        self::assertStringNotContainsString($codes[0], json_encode(DB::table('mfa_recovery_codes')->get(), JSON_THROW_ON_ERROR));
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['email' => $user->email, 'password' => 'test-only-password'])->assertRedirect(route('mfa.challenge'));
        $this->post('https://souzpir.local/mfa/challenge', ['code' => $codes[0]])->assertRedirect(route('auth.ready'));
        self::assertSame(1, DB::table('mfa_recovery_codes')->whereNotNull('used_at')->count());
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['email' => $user->email, 'password' => 'test-only-password'])->assertRedirect(route('mfa.challenge'));
        $this->post('https://souzpir.local/mfa/challenge', ['code' => $codes[0]])->assertSessionHasErrors('code');
        self::assertNull(session()->getOldInput('code'));
        $this->post('https://souzpir.local/mfa/challenge', ['code' => $codes[1]])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        Http::assertNothingSent();
    }

    public function test_another_account_cannot_use_recovery_code(): void
    {
        Http::preventStrayRequests();
        $this->withServerVariables(['SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1']);
        [$owner, $org, $brand] = $this->cabinet();
        [$member] = $this->member($org, 'viewer', [$brand->id]);
        foreach ([$owner, $member] as $user) {
            $user->forceFill(['mfa_secret' => self::SECRET, 'mfa_confirmed_at' => now()])->save();
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $totp = app(Totp::class)->at(self::SECRET, intdiv(now()->timestamp, 30));
        $code = $this->postJson('https://souzpir.local/profile/security/mfa/recovery', ['code' => $totp])->assertOk()->json('codes.0');
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['email' => $member->email, 'password' => 'test-only-password'])->assertRedirect(route('mfa.challenge'));
        $this->post('https://souzpir.local/mfa/challenge', ['code' => $code])->assertSessionHasErrors('code');
        self::assertSame(0, DB::table('mfa_recovery_codes')->whereNotNull('used_at')->count());
        $this->assertGuest();
        Http::assertNothingSent();
    }
}
