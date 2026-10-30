<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Invitations\AcceptsInvitations;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InvitationsTest extends TenancyTestCase
{
    private function invitation($organization, $inviter, $user, array $brands, array $extra = []): array
    {
        $token = bin2hex(random_bytes(32));
        $invitation = $this->within($organization, null, fn () => Invitation::create([
            'email' => ' '.strtoupper($user->email).' ', 'role' => 'member', 'brand_ids' => $brands, 'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id, 'expires_at' => now()->addDay(), ...$extra,
        ]));

        return [$invitation, $token];
    }

    private function denied(callable $call, int $status = 404): void
    {
        try {
            $call();
            $this->fail('Invalid invitation accepted.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_inspect_and_atomic_accept_bind_email_hash_and_consume_once(): void
    {
        [$owner,$org,$a] = $this->cabinet();
        $user = $this->user();
        [$invitation,$token] = $this->invitation($org, $owner, $user, [$a->id], ['settings' => ['sales_manager' => true]]);
        $service = app(AcceptsInvitations::class);
        $details = $service->inspect($user, $token);
        $this->assertSame($user->email, $details->email);
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
        $this->assertNotSame($token, $invitation->token_hash);
        $membership = $service->accept($user, $token);
        $this->assertSame([$a->id], $membership->brand_ids);
        $this->assertTrue($membership->settings['sales_manager']);
        $this->assertSame($user->id, DB::table('invitations')->where('id', $invitation->id)->value('accepted_by'));
        $this->denied(fn () => $service->accept($user, $token));
        $this->assertSame(1, Membership::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_unknown_wrong_email_expired_at_boundary_revoked_and_foreign_tokens_are_identically_denied(): void
    {
        [$owner,$org,$a] = $this->cabinet();
        [$foreignOwner,$other,$foreign] = $this->cabinet(false);
        $user = $this->user();
        $service = app(AcceptsInvitations::class);
        [$invitation,$token] = $this->invitation($org, $owner, $user, [$a->id]);
        $this->denied(fn () => $service->inspect($this->user(), $token));
        $this->denied(fn () => $service->inspect($user, 'short'));
        $this->denied(fn () => $service->inspect($user, str_repeat('z', 64)));
        $this->freezeSecond();
        DB::table('invitations')->where('id', $invitation->id)->update(['expires_at' => now()]);
        $this->denied(fn () => $service->accept($user, $token));
        DB::table('invitations')->where('id', $invitation->id)->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->denied(fn () => $service->accept($user, $token));
        [, $foreignToken] = $this->invitation($other, $foreignOwner, $user, [$foreign->id]);
        $this->denied(fn () => $service->accept($user, $foreignToken));
        $this->assertSame(0, Membership::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_unverified_or_changed_email_is_denied_and_existing_owner_is_never_downgraded(): void
    {
        [$owner,$org,$a] = $this->cabinet();
        $user = $this->user(false);
        [, $token] = $this->invitation($org, $owner, $user, [$a->id]);
        $service = app(AcceptsInvitations::class);
        $this->denied(fn () => $service->accept($user, $token), 403);
        [, $ownerToken] = $this->invitation($org, $owner, $owner, [$a->id], ['role' => 'viewer']);
        $membership = $service->accept($owner, $ownerToken);
        $this->assertSame('owner', $membership->role);
        $this->assertSame([], $membership->brand_ids);
        $user->forceFill(['email_verified_at' => now()])->save();
        DB::table('users')->where('id', $user->id)->update(['email' => 'changed@example.test']);
        $this->denied(fn () => $service->accept($user, $token));
    }

    public function test_existing_disabled_member_is_not_reactivated_or_given_more_brands(): void
    {
        [$owner,$org,$a,$b] = $this->cabinet();
        [$user,$existing] = $this->member($org, 'viewer', [$a->id]);
        $this->within($org, null, fn () => $existing->update(['status' => 'disabled']));
        [, $token] = $this->invitation($org, $owner, $user, [$a->id, $b->id], ['role' => 'admin']);
        $membership = app(AcceptsInvitations::class)->accept($user, $token);
        $this->assertSame('disabled', $membership->status);
        $this->assertSame('viewer', $membership->role);
        $this->assertSame([$a->id], $membership->brand_ids);
    }

    public function test_invitation_grant_and_revocation_cannot_be_rewritten(): void
    {
        [$owner,$org,$a] = $this->cabinet();
        $user = $this->user();
        [$invitation] = $this->invitation($org, $owner, $user, [$a->id]);
        $this->within($org, null, function () use ($invitation): void {
            try {
                $invitation->role = 'admin';
                $invitation->save();
                $this->fail('Invitation grant changed.');
            } catch (\LogicException) {
                $invitation->refresh();
                $this->assertSame('member', $invitation->role);
            }
            $invitation->revoked_at = now();
            $invitation->save();
            $this->expectException(\LogicException::class);
            $invitation->revoked_at = null;
            $invitation->save();
        });
    }

    public function test_acceptance_rolls_back_membership_when_consumption_fails(): void
    {
        [$owner,$org,$a] = $this->cabinet();
        $user = $this->user();
        [$invitation,$token] = $this->invitation($org, $owner, $user, [$a->id]);
        Invitation::updating(fn () => throw new \RuntimeException('Simulated storage failure'));
        try {
            app(AcceptsInvitations::class)->accept($user, $token);
            $this->fail('Expected storage failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated storage failure', $e->getMessage());
        } finally {
            Invitation::flushEventListeners();
            Invitation::clearBootedModels();
        }
        $this->assertSame(0,Membership::withoutGlobalScopes()->where('user_id',$user->id)->count());
        $this->assertNull(DB::table('invitations')->where('id',$invitation->id)->value('accepted_at'));
        $this->assertNull(app(TenantContext::class)->organizationId);
    }
}
