<?php

namespace Tests\Feature\Shell\Profile;

use App\Shell\Auth\AuthSessionRegistry;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProfileContextRecoveryTest extends TenancyTestCase
{
    public function test_recovery_rotates_the_locked_session_and_rejects_the_old_one(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $oldId = session()->getId();
        $this->postJson('/brand/switch', ['recover_context' => true])->assertOk()->assertExactJson(['status' => 'recovered']);
        self::assertNotSame($oldId, session()->getId());
        $session = new Store('old-session', new ArraySessionHandler(120));
        $session->setId($oldId);
        $session->start();
        $session->put('auth.version', $owner->auth_version);
        $oldRequest = Request::create('/brand/switch', 'POST');
        $oldRequest->setLaravelSession($session);
        $oldRequest->setUserResolver(fn () => $owner);
        try {
            app(AuthSessionRegistry::class)->withinActive($oldRequest, fn () => self::fail('Old session remained active.'));
            self::fail('Old session was accepted.');
        } catch (AuthenticationException) {
            self::assertTrue(true);
        }
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->postJson('/brand/switch', ['recover_context' => true])->assertOk()->assertExactJson(['status' => 'recovered']);
    }

    public function test_revoked_session_and_invalid_input_are_rejected(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/brand/switch', ['recover_context' => 'yes'])->assertUnprocessable();
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $this->postJson('/brand/switch', ['recover_context' => true])->assertUnauthorized();
    }
}
