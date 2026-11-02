<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;

final class BrandSwitchReceiptTest extends TenancyTestCase
{
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
