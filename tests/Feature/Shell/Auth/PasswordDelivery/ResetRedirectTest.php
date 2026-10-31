<?php

namespace Tests\Feature\Shell\Auth\PasswordDelivery;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResetRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_password_returns_only_to_the_internal_reset_page(): void
    {
        $token = str_repeat('a', 64);
        $email = 'synthetic@example.test';
        $response = $this->withHeader('Referer', 'https://outside.example/reset-password/synthetic-secret?email=synthetic@example.test')
            ->post('/reset-password', ['token' => $token, 'email' => $email, 'password' => 'short', 'password_confirmation' => 'short']);
        $response->assertStatus(302)->assertHeader('Location', route('password.reset', ['token' => $token, 'email' => $email], absolute: false))
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertSessionHasErrors('password');
        self::assertNull(session()->getOldInput('token'));
        self::assertNull(session()->getOldInput('password'));
    }

    public function test_invalid_token_returns_to_internal_page_without_attacker_referer(): void
    {
        $response = $this->withHeader('Referer', 'https://outside.example/path?token=synthetic-secret')
            ->post('/reset-password', ['token' => '../outside', 'email' => 'synthetic@example.test', 'password' => 'a-long-password-example', 'password_confirmation' => 'a-long-password-example']);
        $response->assertStatus(302)->assertHeader('Location', '/forgot-password')->assertSessionHasErrors('email');
        self::assertNull(session()->getOldInput('token'));
    }

    public function test_expired_or_unknown_token_never_redirects_to_external_referer(): void
    {
        $token = str_repeat('b', 64);
        $email = 'synthetic@example.test';
        $response = $this->withHeader('Referer', 'https://outside.example/path?token=synthetic-secret')
            ->post('/reset-password', ['token' => $token, 'email' => $email, 'password' => 'a-long-password-example', 'password_confirmation' => 'a-long-password-example']);
        $response->assertStatus(302)->assertHeader('Location', route('password.reset', ['token' => $token, 'email' => $email], absolute: false))->assertSessionHasErrors('email');
    }
}
