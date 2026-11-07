<?php

namespace Tests\Feature\Shell\Team;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Invitations\AcceptsInvitations;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class TeamLifecycleTest extends TeamTestCase
{
    public function test_five_role_matrix_uses_real_permissions_and_read_only_roles_cannot_mutate(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $actors = ['owner' => $owner];
        foreach ([['admin', false], ['member', false], ['member', true], ['viewer', false]] as [$role, $manager]) {
            [$user] = $this->member($org, $role, [$brand->id], $manager);
            $actors[$role === 'member' ? ($manager ? 'manager' : 'editor') : $role] = $user;
        }
        foreach ($actors as $role => $actor) {
            $this->signIn($actor, $brand);
            $expected = in_array($role, ['owner', 'admin', 'viewer'], true) ? 200 : 403;
            self::assertSame($expected, $this->get('/team')->status());
            $response = $this->get('/team/roles');
            self::assertSame($expected, $response->status());
            if ($expected === 200) {
                $response->assertInertia(fn (Assert $page) => $page->component('Shell/Team/Roles')
                    ->has('team.roles', 5)->where('team.can_manage', $role !== 'viewer')
                    ->where('team.permissions', fn ($rows) => collect($rows)->every(fn ($row) => $row['roles'] === config('permissions.abilities')[$row['key']])));
            }
            if (! in_array($role, ['owner', 'admin'], true)) {
                self::assertSame(403, $this->postJson('/team/invitations', ['email' => 'blocked@example.test', 'role' => 'viewer', 'brand_ids' => [$brand->id]])->status());
            }
        }
        self::assertSame(0, Invitation::withoutGlobalScopes()->count());
        Mail::assertNothingSent();
    }

    public function test_invitation_hash_ttl_resend_and_existing_acceptance_are_real_and_secret_is_ephemeral(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $invitee = $this->user('Получатель');
        $this->signIn($owner, $brand);
        $issued = $this->invitation(strtoupper($invitee->email), 'manager', [$brand->id]);
        self::assertSame('fallback', $issued['delivery']);
        self::assertSame('disabled', $issued['delivery_status']);
        self::assertSame('/invitations/open', parse_url($issued['invitation_url'], PHP_URL_PATH));
        self::assertNull(parse_url($issued['invitation_url'], PHP_URL_QUERY));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/D', $this->token($issued));
        $first = Invitation::withoutGlobalScopes()->findOrFail($issued['id']);
        self::assertSame('member', $first->role);
        self::assertTrue($first->settings['sales_manager']);
        self::assertSame(7, (int) now()->startOfSecond()->diffInDays($first->expires_at->startOfSecond()));
        self::assertTrue(hash_equals($first->token_hash, hash('sha256', $this->token($issued))));
        self::assertFalse(str_contains(json_encode(DB::table('audit_logs')->get()), $this->token($issued)));
        self::assertFalse(str_contains(json_encode(session()->all()), $this->token($issued)));
        self::assertFalse(str_contains($this->get('/team')->getContent(), $this->token($issued)));
        self::assertSame(422, $this->postJson('/team/invitations', ['email' => $invitee->email, 'role' => 'viewer', 'brand_ids' => [$brand->id]])->status());
        $response = $this->postJson('/team/invitations/'.$first->id.'/resend');
        self::assertSame(201, $response->status());
        $second = $response->json('data');
        self::assertNotSame($first->id, $second['id']);
        self::assertSame('/invitations/open', parse_url($second['invitation_url'], PHP_URL_PATH));
        self::assertNull(parse_url($second['invitation_url'], PHP_URL_QUERY));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/D', $this->token($second));
        self::assertNotNull($first->fresh()->revoked_at);
        self::assertFalse(hash_equals($this->token($issued), $this->token($second)));
        try {
            app(AcceptsInvitations::class)->inspect($invitee, $this->token($issued));
            self::fail('Revoked invitation accepted.');
        } catch (HttpExceptionInterface $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        $this->signIn($invitee, $brand);
        app(AcceptsInvitations::class)->accept($invitee, $this->token($second));
        $membership = Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('user_id', $invitee->id)->firstOrFail();
        self::assertSame('member', $membership->role);
        self::assertTrue($membership->settings['sales_manager']);
        self::assertSame([$brand->id], $membership->brand_ids);
        try {
            app(AcceptsInvitations::class)->accept($invitee, $this->token($second));
            self::fail('Consumed invitation accepted.');
        } catch (HttpExceptionInterface $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        Mail::assertNothingSent();
    }

    public function test_issued_fragment_works_with_session_exchange_and_does_not_enter_request_urls(): void
    {
        if (! Route::has('invitation.open') || ! Route::has('invitation.exchange')) {
            self::markTestSkipped('Requires the shared invitation safety routes from main.');
        }
        [$owner, $org, $brand] = $this->cabinet();
        $invitee = $this->user('Получатель');
        $this->signIn($owner, $brand);
        $issued = $this->invitation($invitee->email, 'viewer', [$brand->id]);
        $token = $this->token($issued);
        $this->signIn($invitee, $brand);
        $openPath = parse_url($issued['invitation_url'], PHP_URL_PATH);
        self::assertSame('/invitations/open', $openPath);
        $open = $this->get($openPath)->assertOk();
        self::assertStringNotContainsString($token, $open->getContent());
        $this->postJson('/invitations/exchange', ['token' => $token])->assertOk()->assertJsonPath('ok', true);
        $show = $this->get('/invitations/show')->assertOk();
        self::assertStringNotContainsString($token, $show->getContent());
        $this->post('/invitations/accept')->assertRedirect();
        self::assertSame('viewer', Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('user_id', $invitee->id)->firstOrFail()->role);
        $this->post('/invitations/accept')->assertNotFound();
        self::assertFalse(str_contains(json_encode(DB::table('audit_logs')->get()), $token));
        self::assertFalse(str_contains(json_encode(session()->all()), $token));
    }

    public function test_team_invitation_allows_guest_registration_verification_and_acceptance(): void
    {
        if (! Route::has('invitation.open') || ! Route::has('invitation.exchange')) {
            self::markTestSkipped('Requires the shared invitation safety routes from main.');
        }

        [$owner, $org, $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $email = 'team-guest@example.test';
        $issued = $this->invitation($email, 'manager', [$brand->id]);
        $token = $this->token($issued);
        self::assertSame('/invitations/open', parse_url($issued['invitation_url'], PHP_URL_PATH));
        self::assertNull(parse_url($issued['invitation_url'], PHP_URL_QUERY));
        $this->post('/logout')->assertRedirect();
        $this->withCookie(config('session.cookie'), session()->getId());

        $this->get('/invitations/open')->assertOk();
        $this->postJson('/invitations/exchange', ['token' => $token])->assertOk();
        self::assertStringNotContainsString($token, serialize(session()->all()));
        self::assertStringNotContainsString($token, request()->getContent());
        $this->get('/invitations/show')->assertOk()->assertDontSee($token, false);
        $this->post('/invitations/register', ['name' => 'Новый участник', 'password' => 'synthetic-password-123',
            'password_confirmation' => 'synthetic-password-123', 'terms' => true])->assertRedirect(route('verification.notice'));
        $guest = User::where('email', $email)->firstOrFail();
        $this->assertDatabaseMissing('memberships', ['organization_id' => $org->id, 'user_id' => $guest->id]);
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post('/invitations/accept')->assertRedirect(route('verification.notice'));
        $verify = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $guest->id, 'hash' => sha1($guest->email)]);
        $this->get($verify)->assertOk();
        self::assertNull($guest->fresh()->email_verified_at);
        $this->post($verify)->assertRedirect(route('invitation.show'));
        $this->post('/invitations/accept')->assertRedirect(route('auth.ready'));
        $membership = Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('user_id', $guest->id)->firstOrFail();
        self::assertSame('member', $membership->role);
        self::assertTrue($membership->settings['sales_manager']);
        self::assertSame([$brand->id], $membership->brand_ids);
        $this->post('/invitations/accept')->assertNotFound();
        self::assertStringNotContainsString($token, json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR));
        Mail::assertNothingSent();
    }

    public function test_expiry_boundary_and_revoke_block_acceptance(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $invitee = $this->user();
        $this->signIn($owner, $brand);
        $issued = $this->invitation($invitee->email, 'viewer', [$brand->id]);
        $expires = Invitation::withoutGlobalScopes()->findOrFail($issued['id'])->expires_at;
        $this->travelTo($expires);
        try {
            app(AcceptsInvitations::class)->inspect($invitee, $this->token($issued));
            self::fail('Expired invitation accepted.');
        } catch (HttpExceptionInterface $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        $this->travelBack();
        self::assertSame(200, $this->postJson('/team/invitations/'.$issued['id'].'/revoke')->status());
        self::assertSame(200, $this->postJson('/team/invitations/'.$issued['id'].'/revoke')->status());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'team.invitation.revoked')->count());
        try {
            app(AcceptsInvitations::class)->inspect($invitee, $this->token($issued));
            self::fail('Revoked invitation accepted.');
        } catch (HttpExceptionInterface $error) {
            self::assertSame(404, $error->getStatusCode());
        }
    }

    public function test_resending_expired_invitation_cannot_create_second_pending_grant(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->signIn($owner, $brand);
        $first = $this->invitation('same@example.test', 'admin', []);
        $this->travel(8)->days();
        $this->signIn($owner, $brand);
        $second = $this->invitation('same@example.test', 'viewer', [$brand->id]);
        self::assertSame(422, $this->postJson('/team/invitations/'.$first['id'].'/resend')->status());
        self::assertSame(2, Invitation::withoutGlobalScopes()->count());
        self::assertNull(Invitation::withoutGlobalScopes()->findOrFail($first['id'])->revoked_at);
        self::assertTrue(Invitation::withoutGlobalScopes()->findOrFail($second['id'])->expires_at->isFuture());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'team.invitation.resent')->count());
    }

    public function test_member_role_and_brand_changes_preserve_other_settings_and_protect_owner_self(): void
    {
        [$owner, $org, $brand, $other, $ownerMembership] = $this->cabinet();
        [, $member] = $this->member($org, 'member', [$brand->id, $other->id]);
        $this->signIn($owner, $brand);
        self::assertSame(200, $this->patchJson('/team/'.$member->id, ['role' => 'manager', 'brand_ids' => [$brand->id]])->status());
        $member->refresh();
        self::assertSame('member', $member->role);
        self::assertTrue($member->settings['sales_manager']);
        self::assertSame('value', $member->settings['preserved']);
        self::assertSame([$brand->id], $member->brand_ids);
        self::assertSame(200, $this->patchJson('/team/'.$member->id, ['role' => 'admin', 'brand_ids' => []])->status());
        self::assertSame('admin', $member->fresh()->role);
        self::assertFalse($member->fresh()->settings['sales_manager']);
        self::assertSame(422, $this->patchJson('/team/'.$member->id, ['role' => 'owner', 'brand_ids' => []])->status());
        self::assertSame(403, $this->patchJson('/team/'.$ownerMembership->id, ['role' => 'viewer', 'brand_ids' => [$brand->id]])->status());
        self::assertSame(403, $this->postJson('/team/'.$ownerMembership->id.'/disable')->status());
        self::assertSame(2, DB::table('audit_logs')->where('action', 'team.member.updated')->count());
    }

    public function test_list_filters_and_independent_pagination_use_real_scoped_rows(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$editor] = $this->member($org, 'member', [$brand->id]);
        $this->member($org, 'member', [$other->id], true);
        [, $viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->signIn($owner, $brand);
        $this->invitation('pending-a@example.test', 'viewer', [$brand->id]);
        $this->invitation('pending-b@example.test', 'manager', [$other->id]);
        $this->get('/team?q='.urlencode($editor->email))->assertInertia(fn (Assert $page) => $page->where('team.members.total', 1)->where('team.invitations.total', 0));
        $this->get('/team?role=manager&brand_id='.$other->id)->assertInertia(fn (Assert $page) => $page->where('team.members.total', 1)->where('team.invitations.total', 1));
        $this->get('/team?status=pending')->assertInertia(fn (Assert $page) => $page->where('team.members.total', 0)->where('team.invitations.total', 2));
        DB::table('memberships')->where('id', $viewer->id)->update(['status' => 'disabled']);
        $this->get('/team?status=disabled')->assertInertia(fn (Assert $page) => $page->where('team.members.total', 1)->where('team.invitations.total', 0)->where('team.members.data.0.role', 'viewer'));
        for ($i = 0; $i < 21; $i++) {
            $this->member($org, 'member', [$brand->id]);
        }
        $this->get('/team?status=active&members_page=2')->assertInertia(fn (Assert $page) => $page->where('team.members.current_page', 2)->where('team.members.total', 24)->has('team.members.data', 4));
    }

    public function test_activity_is_actor_scoped_brand_scoped_and_permission_gated(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$target, $member] = $this->member($org, 'viewer', [$brand->id, $other->id]);
        foreach ([[$target->id, $brand->id], [$target->id, null], [$target->id, $other->id], [$owner->id, $brand->id]] as [$actor, $scope]) {
            DB::transaction(fn () => app(AuditWriter::class)->record(new TenantContext($org->id, $scope, $actor),
                new AuditEntry('fixture.private.action', 'unrelated', 'fixture', after: ['private_payload' => 'DO_NOT_EXPOSE'])));
        }
        $this->signIn($owner, $brand);
        $this->get('/team/'.$member->id)->assertInertia(fn (Assert $page) => $page->component('Shell/Team/Show')
            ->where('team.activity_available', true)->where('team.activity.total', 2)->has('team.activity.data', 2)
            ->where('team.activity.data.0.description', 'Действие в кабинете')->missing('team.activity.data.0.after'));
        self::assertFalse(str_contains($this->get('/team/'.$member->id)->getContent(), 'DO_NOT_EXPOSE'));
        $this->signIn($target, $brand);
        $this->get('/team/'.$member->id)->assertInertia(fn (Assert $page) => $page->where('team.activity_available', false)->has('team.activity.data', 0));
    }
}
