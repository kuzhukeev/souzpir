<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;

final class BrandSwitchReceiptTest extends TenancyTestCase
{
    public function test_keyed_logout_revokes_session_and_blocks_late_brand_switch(): void
    {
        [$owner, $org, $a, $b] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $a->id]);
        $this->post('/logout', [], ['X-Context-Change-Key' => 'synthetic-logout-key-123456'])->assertRedirect('/login');
        $this->assertSame(1, DB::table('c02_session_receipts')->where('operation', 'profile.logout')->count());
        $this->assertNotNull(DB::table('auth_session_registry')->where('user_id', $owner->id)->value('revoked_at'));
        $this->postJson('/brand/switch', ['brand_id' => $b->id], ['X-Context-Change-Key' => 'synthetic-switch-key-123456'])->assertUnauthorized();
        $this->assertNotSame($b->id, DB::table('auth_session_registry')->where('user_id', $owner->id)->value('selected_brand_id'));
    }

    public function test_revoked_cookie_reads_only_its_brand_switch_receipt_over_http(): void
    {
        [$owner, $org, $a, $b] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $a->id]);
        $oldCookie = session()->getId();
        $key = 'synthetic-switch-recover-123456';
        $this->post('/brand/switch', ['brand_id' => $b->id], ['X-Context-Change-Key' => $key])->assertRedirect('/auth/ready');
        $this->post('/logout', [], ['X-Context-Change-Key' => 'synthetic-logout-recover-123456'])->assertRedirect('/login');
        $this->withCookie(config('session.cookie'), $oldCookie)
            ->getJson('/brand/switch/recover?brand_id='.$b->id, ['X-Context-Change-Key' => $key])
            ->assertOk()->assertExactJson(['switched' => true]);
        $this->withCookie(config('session.cookie'), $oldCookie)
            ->getJson('/brand/switch/recover?brand_id='.$b->id, ['X-Context-Change-Key' => 'different-switch-key-123456'])
            ->assertUnauthorized();
        $this->assertSame($b->id, DB::table('auth_session_registry')->where('user_id', $owner->id)->value('selected_brand_id'));
    }

    public function test_repeated_switch_key_returns_same_receipt_without_switching_twice(): void
    {
        [$owner,$org,$a,$b] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $a->id]);
        $headers = ['X-Context-Change-Key' => 'synthetic-change-key-123456'];
        $this->post('/brand/switch', ['brand_id' => $b->id], $headers)->assertRedirect('/auth/ready');
        $revision = DB::table('auth_session_registry')->where('user_id', $owner->id)->value('brand_revision');
        $this->post('/brand/switch', ['brand_id' => $b->id], $headers)->assertRedirect('/auth/ready');
        $this->assertSame((string) $revision, (string) DB::table('auth_session_registry')->where('user_id', $owner->id)->value('brand_revision'));
        $this->post('/brand/switch', ['brand_id' => $a->id], $headers)->assertStatus(409);
        $this->assertSame($b->id, DB::table('auth_session_registry')->where('user_id', $owner->id)->value('selected_brand_id'));
        $this->postJson('/brand/switch', ['brand_id' => $a->id], ['X-Context-Change-Key' => 'short'])->assertUnprocessable();
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $this->postJson('/brand/switch', ['brand_id' => $b->id], $headers)->assertUnauthorized();
        $this->assertSame($b->id, DB::table('auth_session_registry')->where('user_id', $owner->id)->value('selected_brand_id'));
    }
}
