<?php

namespace Tests\Feature\Shell\Auth;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Tenancy\TenancyTestCase;

final class LogoutReceiptTest extends TenancyTestCase
{
    public function test_lost_logout_response_can_be_recovered_with_old_cookie_only(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $oldCookie = session()->getId();
        $key = 'synthetic-logout-recover-123456';
        $this->getJson('/logout/recover', ['X-Context-Change-Key' => $key])->assertUnauthorized();
        $this->post('/logout', [], ['X-Context-Change-Key' => $key])->assertRedirect('/login');
        $this->withCookie(config('session.cookie'), $oldCookie)
            ->getJson('/logout/recover', ['X-Context-Change-Key' => $key])
            ->assertOk()->assertExactJson(['logged_out' => true]);
        $this->withCookie(config('session.cookie'), $oldCookie)
            ->getJson('/logout/recover', ['X-Context-Change-Key' => 'wrong-logout-key-123456'])
            ->assertUnauthorized();
        $this->withCookie(config('session.cookie'), $oldCookie)
            ->getJson('/profile')->assertUnauthorized();
        $this->assertSame(1, DB::table('c02_session_receipts')->where('operation', 'profile.logout')->count());
    }
}
