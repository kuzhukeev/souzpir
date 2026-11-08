<?php

namespace Tests\Feature\Shell\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\MfaTestDatabase;
use Tests\TestCase;

final class RegisterBootstrapE101JTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        Notification::fake();
    }

    private function organization(): Organization
    {
        $org = Organization::create(['name' => 'Cabinet']);
        config(['features.E0-02.defaults.organization_id' => $org->id]);

        return $org;
    }

    public function test_page_announces_whether_code_is_needed(): void
    {
        config(['features.E1-01.defaults.bootstrap_token_hash' => null]);
        $this->get('/register')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Shell/Auth/Register')->where('needsCode', false));

        config(['features.E1-01.defaults.bootstrap_token_hash' => hash('sha256', 'any-secret-value')]);
        $this->get('/register')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Shell/Auth/Register')->where('needsCode', true));
    }

    public function test_registration_without_configured_hash_does_not_require_or_check_code(): void
    {
        $this->organization();
        config(['features.E1-01.defaults.bootstrap_token_hash' => null]);
        $data = ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secure-password-example', 'password_confirmation' => 'secure-password-example', 'terms' => true, 'ai_consent' => true];
        $this->post('/register', $data)->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'role' => 'owner']);
    }

    public function test_registration_without_configured_hash_ignores_submitted_code(): void
    {
        $this->organization();
        config(['features.E1-01.defaults.bootstrap_token_hash' => null]);
        $data = ['name' => 'Owner', 'email' => 'owner2@example.test', 'password' => 'secure-password-example', 'password_confirmation' => 'secure-password-example', 'terms' => true, 'ai_consent' => true, 'bootstrap_code' => 'this-value-is-ignored-and-not-checked'];
        $this->post('/register', $data)->assertRedirect(route('verification.notice'));
        $this->assertDatabaseHas('users', ['email' => 'owner2@example.test']);
    }

    public function test_second_registration_without_code_is_rejected_once_organization_has_an_owner(): void
    {
        $this->organization();
        config(['features.E1-01.defaults.bootstrap_token_hash' => null]);
        $data = ['name' => 'Owner', 'email' => 'owner3@example.test', 'password' => 'secure-password-example', 'password_confirmation' => 'secure-password-example', 'terms' => true, 'ai_consent' => true];
        $this->post('/register', $data)->assertRedirect(route('verification.notice'));
        $this->assertDatabaseCount('users', 1);

        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/logout')->assertRedirect(route('login'));

        $this->post('/register', [...$data, 'email' => 'owner4@example.test'])->assertSessionHasErrors('bootstrap_code');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_still_requires_valid_code_when_hash_is_configured(): void
    {
        $this->organization();
        $secret = str_repeat('z', 40);
        config(['features.E1-01.defaults.bootstrap_token_hash' => hash('sha256', $secret)]);
        $data = ['name' => 'Owner', 'email' => 'owner5@example.test', 'password' => 'secure-password-example', 'password_confirmation' => 'secure-password-example', 'terms' => true, 'ai_consent' => true];
        $this->post('/register', $data)->assertSessionHasErrors('bootstrap_code');
        $this->assertDatabaseCount('users', 0);
        $this->post('/register', [...$data, 'bootstrap_code' => $secret])->assertRedirect(route('verification.notice'));
        $this->assertDatabaseCount('users', 1);
    }
}
