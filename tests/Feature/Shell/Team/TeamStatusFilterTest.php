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

final class TeamStatusFilterTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function invite(Organization $org, User $owner, string $email, string $brand): Invitation
    {
        return $this->within($org, null, fn () => Invitation::create([
            'email' => $email, 'role' => 'viewer', 'brand_ids' => [$brand],
            'token_hash' => hash('sha256', Str::random(64)), 'invited_by' => $owner->id, 'expires_at' => now()->addDay(),
        ]));
    }

    public function test_status_role_and_brand_filter_both_collections_before_pagination(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        for ($i = 0; $i < 27; $i++) {
            $this->member($org, 'viewer', [$first->id]);
            $this->invite($org, $owner, "status-{$i}@example.test", $first->id);
        }
        for ($i = 0; $i < 26; $i++) {
            [, $membership] = $this->member($org, 'viewer', [$first->id]);
            DB::table('memberships')->where('id', $membership->id)->update(['status' => 'disabled']);
        }
        $this->member($org, 'viewer', [$second->id]);
        $this->member($org, 'member', [$first->id]);
        $this->invite($org, $owner, 'other-brand@example.test', $second->id);
        $expired = $this->invite($org, $owner, 'expired@example.test', $first->id);
        DB::table('invitations')->where('id', $expired->id)->update(['expires_at' => now()]);
        [$foreignOwner, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $this->invite($foreignOrg, $foreignOwner, 'foreign@example.test', $foreignBrand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $base = '/team?role=viewer&brand='.$first->id;
        $this->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('statusFilter', 'all')->where('members.total', 53)->where('invitations.total', 27));
        $this->get($base.'&status=active&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('statusFilter', 'active')->where('members.total', 27)->has('members.data', 2)->where('invitations.total', 0));
        $this->get($base.'&status=disabled&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('statusFilter', 'disabled')->where('members.total', 26)->has('members.data', 1)->where('members.data.0.status', 'disabled')->where('invitations.total', 0));
        $response = $this->get($base.'&status=invited&invitation_page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('statusFilter', 'invited')->where('members.total', 0)->where('invitations.total', 27)->has('invitations.data', 2));
        $content = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('foreign@example.test', $content);
        $this->assertStringNotContainsString('other-brand@example.test', $content);
        $this->assertStringNotContainsString('expired@example.test', $content);
        $this->assertStringNotContainsString('token_hash', $content);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_status_filter_rejects_invalid_values_and_cannot_expand_actor_access(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$first->id]);
        [$editor] = $this->member($org, 'member', [$first->id]);
        $allowed = $this->invite($org, $owner, 'allowed-status@example.test', $first->id);
        $hidden = $this->invite($org, $owner, 'hidden-status@example.test', $second->id);
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id]);
        $response = $this->get('/team?status=invited')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 0)->where('invitations.total', 1)->where('invitations.data.0.id', $allowed->id));
        $content = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($hidden->email, $content);
        $this->assertStringNotContainsString($second->id, $content);
        $this->get('/team?status=invited&brand='.$second->id)->assertNotFound();
        foreach (['unknown', 'ACTIVE', 'pending'] as $invalid) {
            $this->get('/team?status='.$invalid)->assertNotFound();
        }
        $this->get('/team?status[]=active')->assertNotFound();
        $this->actingAs($editor)->withSession(['brand_id' => $first->id])->get('/team?status=active')->assertForbidden();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
