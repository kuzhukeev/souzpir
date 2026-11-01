<?php

namespace Tests\Feature\Shell\Team;

use App\Models\Organization;
use App\Models\User;
use App\Shell\Team\Models\Invitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TeamInvitationsTest extends TenancyTestCase
{
    private function invite(Organization $organization, User $actor, string $email, array $brands, string $role = 'member'): Invitation
    {
        return $this->within($organization, null, fn () => Invitation::create([
            'email' => $email, 'role' => $role, 'brand_ids' => $brands,
            'token_hash' => hash('sha256', Str::random(64)), 'invited_by' => $actor->id, 'expires_at' => now()->addDay(),
        ]));
    }

    public function test_list_excludes_unavailable_brands_secrets_foreign_and_inactive_invitations(): void
    {
        $this->freezeSecond();
        [$owner, $organization, $first, $second] = $this->cabinet();
        [$viewer] = $this->member($organization, 'viewer', [$first->id]);
        $common = $this->invite($organization, $owner, 'both@example.test', [$first->id, $second->id]);
        $this->invite($organization, $owner, 'hidden@example.test', [$second->id]);
        $wildcard = $this->invite($organization, $owner, 'admin@example.test', [], 'admin');
        $this->invite($organization, $owner, 'brandless@example.test', []);
        $expired = $this->invite($organization, $owner, 'expired@example.test', [$first->id]);
        $revoked = $this->invite($organization, $owner, 'revoked@example.test', [$first->id]);
        $accepted = $this->invite($organization, $owner, 'accepted@example.test', [$first->id]);
        DB::table('invitations')->where('id', $common->id)->update(['exchanged_at' => now()]);
        DB::table('invitations')->where('id', $expired->id)->update(['expires_at' => now()]);
        DB::table('invitations')->where('id', $revoked->id)->update(['revoked_at' => now()]);
        DB::table('invitations')->where('id', $accepted->id)->update(['accepted_at' => now(), 'accepted_by' => $owner->id]);
        [$foreignOwner, $foreignOrganization, $foreignBrand] = $this->cabinet(false);
        $this->invite($foreignOrganization, $foreignOwner, 'foreign@example.test', [$foreignBrand->id]);

        $response = $this->actingAs($viewer)->withSession(['brand_id' => $first->id])->get('/team');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('invitations.data', 2)->where('invitations.total', 2)
            ->where('invitations.as_of', now()->toISOString()));
        $rows = collect($response->inertiaProps('invitations.data'))->keyBy('email');
        $this->assertTrue($rows['both@example.test']['opened']);
        $this->assertFalse($rows['admin@example.test']['opened']);
        $this->assertSame([$first->id], collect($rows['both@example.test']['brands'])->pluck('id')->all());
        $this->assertSame([$first->id], collect($rows['admin@example.test']['brands'])->pluck('id')->all());
        $this->assertSame(['id', 'email', 'role_label', 'brands', 'expires_at', 'opened', 'can_revoke'], array_keys($rows['both@example.test']));
        $this->assertFalse($rows['both@example.test']['can_revoke']);
        $content = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ([$second->id, $foreignBrand->id, $common->token_hash, $wildcard->token_hash, 'hidden@example.test', 'brandless@example.test', 'foreign@example.test', 'expired@example.test', 'revoked@example.test', 'accepted@example.test', 'token_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])->get('/team')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('invitations.data', 4)->where('invitations.total', 4));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_invitation_pages_require_team_read_and_keep_real_counts(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        [$editor] = $this->member($organization, 'member', [$brand->id]);
        for ($i = 0; $i < 27; $i++) {
            $this->invite($organization, $owner, "guest{$i}@example.test", [$brand->id]);
        }
        $this->actingAs($editor)->withSession(['brand_id' => $brand->id])->get('/team')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/team')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('invitations.data', 25)->where('invitations.total', 27)->where('invitations.last_page', 2));
        $this->get('/team?invitation_page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('invitations.data', 2)->where('invitations.current_page', 2));
        $this->get('/team?invitation_page=3')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('invitations.data', 0)->where('invitations.total', 27));
        $this->get('/team?invitation_page=0')->assertNotFound();
        $this->get('/team?invitation_page=bad')->assertNotFound();
    }

    public function test_server_snapshot_and_filters_exclude_the_exact_expiry_boundary(): void
    {
        $this->freezeSecond();
        [$owner, $organization, $first, $second] = $this->cabinet();
        $boundary = $this->invite($organization, $owner, 'boundary@example.test', [$first->id], 'viewer');
        $future = $this->invite($organization, $owner, 'future@example.test', [$first->id], 'viewer');
        $this->invite($organization, $owner, 'other-brand@example.test', [$second->id], 'viewer');
        $this->invite($organization, $owner, 'other-role@example.test', [$first->id], 'member');
        [$foreignOwner, $foreignOrganization, $foreignBrand] = $this->cabinet(false);
        $this->invite($foreignOrganization, $foreignOwner, 'foreign@example.test', [$foreignBrand->id], 'viewer');
        DB::table('invitations')->where('id', $boundary->id)->update(['expires_at' => now()]);
        DB::table('invitations')->where('id', $future->id)->update(['expires_at' => now()->addSecond()]);

        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $url = '/team?role=viewer&brand='.$first->id.'&status=invited';
        $response = $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('invitations.as_of', now()->toISOString())
            ->where('invitations.total', 1)->where('invitations.data.0.email', 'future@example.test'));
        foreach (['boundary@example.test', 'other-brand@example.test', 'other-role@example.test', 'foreign@example.test'] as $hidden) {
            $this->assertStringNotContainsString($hidden, json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR));
        }

        $this->travel(1)->seconds();
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('invitations.as_of', now()->toISOString())->where('invitations.total', 0));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
