<?php

namespace Tests\Feature\Shell\Team;

use App\Shell\Team\Models\Invitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TeamActionsTest extends TenancyTestCase
{
    public function test_repeat_issues_a_new_link_with_same_grant_and_respects_rights(): void
    {
        config(['features.E1-06B.defaults.attempt_hmac_key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'app.url' => 'http://localhost']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        Http::fake();
        Mail::fake();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $invitation = $this->within($org, null, fn () => Invitation::create(['email' => 'invite@example.test', 'role' => 'member', 'brand_ids' => [$brand->id], 'settings' => ['sales_manager' => true], 'token_hash' => hash('sha256', Str::random(64)), 'invited_by' => $owner->id, 'expires_at' => now()->addDay()]));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/team')->assertOk()->inertiaProps('teamBrandRevision');
        $url = '/team/invitations/'.$invitation->id.'/repeat';
        $this->withHeader('X-Brand-Revision', $revision)->postJson($url, ['attempt_key' => str_repeat('a', 64)])->assertCreated()->assertJsonStructure(['link']);
        $this->assertSame(2, DB::table('invitations')->where('organization_id', $org->id)->where('email', 'invite@example.test')->count());
        $this->assertNull(DB::table('invitations')->where('id', $invitation->id)->value('revoked_at'));
        $this->postJson($url, ['attempt_key' => 'bad'])->assertUnprocessable();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->postJson($url, ['attempt_key' => str_repeat('b', 64)])->assertForbidden();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_transfer_moves_only_current_brand_dialogs_to_authorized_colleague(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$source, $sourceMember] = $this->member($org, 'member', [$brand->id, $other->id], ['sales_manager' => true]);
        [$recipient] = $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $dialogs = [];
        foreach ([$brand, $other] as $item) {
            $id = (string) Str::ulid();
            $dialogs[] = $id;
            DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'assigned_user_id' => $source->id, 'mode' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $url = '/team/'.$sourceMember->id.'/transfer-dialogs';
        $this->post($url, ['recipient_id' => $viewer->id])->assertSessionHasErrors('recipient_id');
        $this->post($url, ['recipient_id' => 'invalid'])->assertSessionHasErrors('recipient_id');
        $this->post($url, ['recipient_id' => $recipient->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('conversations', ['id' => $dialogs[0], 'assigned_user_id' => $recipient->id]);
        $this->assertDatabaseHas('conversations', ['id' => $dialogs[1], 'assigned_user_id' => $source->id]);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $dialogs[0], 'action' => 'seller.dialog.transferred']);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url, ['recipient_id' => $recipient->id])->assertForbidden();
    }
}
