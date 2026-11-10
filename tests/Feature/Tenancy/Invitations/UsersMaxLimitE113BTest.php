<?php

namespace Tests\Feature\Tenancy\Invitations;

use App\Models\Membership;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\Invitations\AcceptsInvitations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class UsersMaxLimitE113BTest extends TenancyTestCase
{
    private function limitUsers(string $org, string $value): void
    {
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'synthetic-'.$plan, 'name' => 'Synthetic', 'is_public' => false]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'plan_id' => $plan, 'starts_at' => now()->subDay()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'users.max', 'value' => $value, 'unit' => 'шт.']);
    }

    private function invitation($organization, $inviter, $user, array $brands): array
    {
        $token = bin2hex(random_bytes(32));
        $invitation = $this->within($organization, null, fn () => Invitation::create([
            'email' => $user->email, 'role' => 'member', 'brand_ids' => $brands, 'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id, 'expires_at' => now()->addDay(),
        ]));

        return [$invitation, $token];
    }

    public function test_acceptance_is_blocked_once_the_plan_user_limit_is_exhausted(): void
    {
        [$owner, $org, $a] = $this->cabinet();
        $this->limitUsers($org->id, '1');
        $user = $this->user();
        [$invitation, $token] = $this->invitation($org, $owner, $user, [$a->id]);
        try {
            app(AcceptsInvitations::class)->accept($user, $token);
            self::fail('Membership created past the plan limit.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        self::assertSame(0, Membership::withoutGlobalScopes()->where('user_id', $user->id)->count());
        self::assertNull(DB::table('invitations')->where('id', $invitation->id)->value('accepted_at'));
    }

    public function test_acceptance_succeeds_under_the_plan_user_limit(): void
    {
        [$owner, $org, $a] = $this->cabinet();
        $this->limitUsers($org->id, '2');
        $user = $this->user();
        [, $token] = $this->invitation($org, $owner, $user, [$a->id]);
        $membership = app(AcceptsInvitations::class)->accept($user, $token);
        self::assertSame($org->id, $membership->organization_id);
        self::assertSame(2, Membership::withoutGlobalScopes()->where('organization_id', $org->id)->where('status', 'active')->count());
    }

    public function test_reactivation_of_an_existing_disabled_member_is_not_blocked_by_the_limit(): void
    {
        [$owner, $org, $a] = $this->cabinet();
        [$user, $existing] = $this->member($org, 'viewer', [$a->id]);
        $this->within($org, null, fn () => $existing->update(['status' => 'disabled']));
        $this->limitUsers($org->id, '1');
        [, $token] = $this->invitation($org, $owner, $user, [$a->id]);
        $membership = app(AcceptsInvitations::class)->accept($user, $token);
        self::assertSame('disabled', $membership->status);
    }
}
