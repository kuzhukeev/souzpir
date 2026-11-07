<?php

namespace Tests\Feature\Shell\Auth;

use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AuthStatesE101ITest extends TenancyTestCase
{
    public function test_failed_login_and_invitation_state_transitions(): void
    {
        [$owner] = $this->cabinet();
        $email = $owner->email;
        RateLimiter::clear('auth.login:'.hash('sha256', $email));
        RateLimiter::clear('auth.login:'.hash('sha256', $email).':lock');
        $this->post('/login', ['email' => $email, 'password' => 'incorrect-password'])
            ->assertRedirect()->assertSessionHasErrors(['email' => 'Неверная почта или пароль']);
        $this->get('/invitations/open')->assertOk()->assertSee("location.replace('/invitations/state?state='+encodeURIComponent(data.state))", false);
        foreach (['expired', 'revoked', 'accepted', 'email_mismatch'] as $state) {
            $this->get('/invitations/state?state='.$state)->assertOk()
                ->assertInertia(fn ($page) => $page->component('Shell/Auth/Invitation')->where('state', $state));
        }
        $this->get('/invitations/state?state=other')->assertNotFound();
    }
}
