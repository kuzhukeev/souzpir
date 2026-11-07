<?php

namespace Tests\Feature\Shell\Auth\PasswordDelivery;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResetRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
    }

    public function test_untrusted_http_does_not_open_or_exchange_a_link(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10', 'SERVER_ADDR' => '192.0.2.20']);
        $this->get('http://outside.example/reset-password/open')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('http://outside.example/reset-password/exchange', ['token' => str_repeat('a', 64), 'email' => 'synthetic@example.test'])->assertForbidden();
        self::assertNull(session('auth.password_recovery'));
    }

    public function test_forwarded_headers_are_rejected_even_when_apache_sets_https_on(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1', 'HTTPS' => 'on']);
        $this->withHeader('X-Forwarded-Proto', 'https');
        $this->get('http://souzpir.local/reset-password/open')->assertForbidden();
        $this->postJson('http://souzpir.local/reset-password/exchange', ['token' => str_repeat('a', 64), 'email' => 'synthetic@example.test'])->assertForbidden();
        $this->get('http://souzpir.local/reset-password')->assertForbidden();
    }

    public function test_invalid_password_returns_only_to_the_internal_reset_page(): void
    {
        $token = 'v2-'.str_repeat('a', 64);
        $email = 'synthetic@example.test';
        $response = $this->withHeader('Referer', 'https://outside.example/reset-password/synthetic-secret?email=synthetic@example.test')
            ->post('/reset-password', ['token' => $token, 'email' => $email, 'password' => 'short', 'password_confirmation' => 'short']);
        $response->assertStatus(302)->assertHeader('Location', '/reset-password')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertSessionHasErrors('password');
        self::assertNull(session()->getOldInput('token'));
        self::assertNull(session()->getOldInput('password'));
        self::assertNull(session()->getOldInput('email'));
    }

    public function test_mismatched_confirmation_returns_russian_validation_error(): void
    {
        $this->post('/reset-password', ['password' => 'a-long-password-example', 'password_confirmation' => 'a-different-password'])
            ->assertRedirect('/reset-password')->assertSessionHasErrors('password');
        self::assertStringContainsString('не совпадает', session('errors')->first('password'));
    }

    public function test_invalid_token_returns_to_internal_page_without_attacker_referer(): void
    {
        $response = $this->withHeader('Referer', 'https://outside.example/path?token=synthetic-secret')
            ->post('/reset-password', ['token' => '../outside', 'email' => 'synthetic@example.test', 'password' => 'a-long-password-example', 'password_confirmation' => 'a-long-password-example']);
        $response->assertStatus(302)->assertHeader('Location', '/reset-password')->assertSessionHasErrors('email');
        self::assertNull(session()->getOldInput('token'));
    }

    public function test_expired_or_unknown_token_never_redirects_to_external_referer(): void
    {
        $token = 'v2-'.str_repeat('b', 64);
        $email = 'synthetic@example.test';
        $response = $this->withHeader('Referer', 'https://outside.example/path?token=synthetic-secret')
            ->post('/reset-password', ['token' => $token, 'email' => $email, 'password' => 'a-long-password-example', 'password_confirmation' => 'a-long-password-example']);
        $response->assertStatus(302)->assertHeader('Location', '/reset-password')->assertSessionHasErrors('email');
        self::assertNull(session()->getOldInput('email'));
    }

    public function test_legacy_url_does_not_establish_a_recovery_grant(): void
    {
        $this->get('/reset-password/'.str_repeat('a', 64).'?email=synthetic%40example.test')->assertNotFound();
        self::assertNull(session('auth.password_recovery'));
    }
}
