<?php

namespace Tests\Feature\Shell\Profile;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProfileThrottleTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1']);
        Http::preventStrayRequests();
    }

    public function test_normal_profile_actions_do_not_spend_credential_buckets(): void
    {
        Storage::fake('local');
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        for ($i = 0; $i < 4; $i++) {
            $this->put('/profile', ['name' => 'Новое имя', 'timezone' => 'UTC', 'language' => 'ru'])->assertRedirect();
        }
        foreach ([false, true] as $enabled) {
            $this->put('/profile/notifications', ['changes' => [['event' => 'shell.member.invited', 'channel' => 'cabinet', 'enabled' => $enabled]]])->assertRedirect();
        }
        $this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('avatar.png', 32, 32)])->assertRedirect();
        $this->delete('/profile/security/sessions')->assertRedirect();
        // actingAs caches its user between HTTP calls; a real next request reloads the advanced version.
        $this->app['auth']->guard()->setUser($user->fresh());
        $this->putJson('/profile/security/password', $this->invalidPassword())->assertUnprocessable()->assertHeader('X-RateLimit-Remaining', '5');
        $this->postJson('/profile/security/mfa/setup', ['password' => 'wrong-password'])->assertUnprocessable()->assertHeader('X-RateLimit-Remaining', '5');
    }

    public function test_password_and_mfa_attempts_keep_independent_six_per_minute_limits(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        for ($i = 0; $i < 6; $i++) {
            $this->putJson('/profile/security/password', $this->invalidPassword())->assertUnprocessable();
        }
        $this->putJson('/profile/security/password', $this->invalidPassword())->assertStatus(429)->assertHeader('Retry-After');
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/profile/security/mfa/setup', ['password' => 'wrong-password'])->assertUnprocessable();
            $this->postJson('/profile/security/mfa/confirm', ['code' => 'invalid'])->assertUnprocessable();
            $this->postJson('/profile/security/mfa/disable', ['password' => 'wrong-password', 'code' => 'invalid'])->assertUnprocessable();
        }
        $this->postJson('/profile/security/mfa/confirm', ['code' => 'invalid'])->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->putJson('/profile/security/password', $this->invalidPassword())->assertUnprocessable();
        $this->postJson('/profile/security/mfa/setup', ['password' => 'wrong-password'])->assertUnprocessable();
    }

    public function test_email_request_limit_does_not_block_cancellation(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/profile/email', ['email' => 'invalid', 'current_password' => 'wrong-password'])->assertUnprocessable();
        }
        $this->postJson('/profile/email', ['email' => 'invalid', 'current_password' => 'wrong-password'])->assertStatus(429);
        $this->delete('/profile/email')->assertRedirect()->assertHeader('X-RateLimit-Remaining', '9');
    }

    public function test_legacy_and_profile_mfa_routes_share_a_per_account_limit_across_sessions(): void
    {
        [$user, $organization, $brand] = $this->cabinet();
        [$other] = $this->member($organization, 'member', [$brand->id]);
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $attempts = [
            ['/mfa/setup', ['password' => 'wrong-password']],
            ['/profile/security/mfa/confirm', ['code' => 'invalid']],
            ['/mfa/disable', ['password' => 'wrong-password', 'code' => 'invalid']],
            ['/profile/security/mfa/setup', ['password' => 'wrong-password']],
            ['/mfa/setup/confirm', ['code' => 'invalid']],
            ['/profile/security/mfa/disable', ['password' => 'wrong-password', 'code' => 'invalid']],
        ];
        foreach ($attempts as [$path, $payload]) {
            $this->postJson('https://cabinet.example.test'.$path, $payload)->assertUnprocessable();
        }

        // A new session must not restore attempts for the same account.
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->postJson('https://cabinet.example.test/mfa/setup', ['password' => 'wrong-password'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->postJson('https://cabinet.example.test/profile/security/mfa/confirm', ['code' => 'invalid'])
            ->assertStatus(429)->assertHeader('Retry-After');

        // Another account has an independent budget, even on the same IP.
        $this->actingAs($other)->withSession(['brand_id' => $brand->id]);
        $this->postJson('https://cabinet.example.test/profile/security/mfa/confirm', ['code' => 'invalid'])
            ->assertUnprocessable();

        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->travel(61)->seconds();
        $this->postJson('https://cabinet.example.test/mfa/setup/confirm', ['code' => 'invalid'])
            ->assertUnprocessable();
    }

    private function invalidPassword(): array
    {
        return ['current_password' => 'wrong-password', 'password' => 'new-valid-password', 'password_confirmation' => 'new-valid-password'];
    }
}
