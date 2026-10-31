<?php

namespace Tests\Feature\Shell\Team;

use App\Models\Organization;
use App\Models\User;
use App\Shell\Team\Models\Invitation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TeamRoleFilterTest extends TenancyTestCase
{
    private function invite(Organization $org, User $actor, string $email, array $brands, string $role, array $settings = []): void
    {
        $this->within($org, null, fn () => Invitation::create([
            'email' => $email, 'role' => $role, 'settings' => $settings, 'brand_ids' => $brands,
            'token_hash' => hash('sha256', Str::random(64)), 'invited_by' => $actor->id, 'expires_at' => now()->addDay(),
        ]));
    }

    public function test_filter_matches_effective_roles_before_both_paginations(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$editor] = $this->member($org, 'member', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        for ($i = 0; $i < 27; $i++) {
            $this->member($org, 'member', [$brand->id], ['sales_manager' => true]);
            $this->invite($org, $owner, "manager{$i}@example.test", [$brand->id], 'member', ['sales_manager' => true]);
        }
        $this->invite($org, $owner, 'editor@example.test', [$brand->id], 'member');
        $this->invite($org, $owner, 'viewer@example.test', [$brand->id], 'viewer');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $response = $this->get('/team?role=manager&page=2&invitation_page=2');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('roleFilter', 'manager')->where('members.total', 27)->where('members.current_page', 2)
            ->has('members.data', 2)->where('invitations.total', 27)->where('invitations.current_page', 2)->has('invitations.data', 2));
        foreach ($response->inertiaProps('members.data') as $row) {
            $this->assertSame('manager', $row['role']);
        }
        foreach ($response->inertiaProps('invitations.data') as $row) {
            $this->assertSame('Менеджер', $row['role_label']);
        }
        $this->get('/team?role=editor')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 1)->where('members.data.0.email', $editor->email)
            ->where('invitations.total', 1)->where('invitations.data.0.email', 'editor@example.test'));
        $this->get('/team?role=viewer')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 1)->where('members.data.0.email', $viewer->email)
            ->where('invitations.total', 1)->where('invitations.data.0.email', 'viewer@example.test'));
        $this->get('/team?role=owner')->assertOk()->assertInertia(fn (Assert $page) => $page->where('members.total', 1)->where('invitations.total', 0));
        $this->get('/team?role=admin')->assertOk()->assertInertia(fn (Assert $page) => $page->where('members.total', 0)->where('invitations.total', 0));
        $this->get('/team?role=all')->assertOk()->assertInertia(fn (Assert $page) => $page->where('members.total', 30)->where('invitations.total', 29));
        $this->get('/team?role=invalid')->assertNotFound();
        $this->get('/team?role[]=viewer')->assertNotFound();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_filter_preserves_permissions_tenant_and_invitation_brand_visibility(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$first->id]);
        [$editor] = $this->member($org, 'member', [$first->id]);
        $this->invite($org, $owner, 'visible@example.test', [$first->id], 'viewer');
        $this->invite($org, $owner, 'hidden@example.test', [$second->id], 'viewer');
        [$foreignOwner, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $this->invite($foreignOrg, $foreignOwner, 'foreign@example.test', [$foreignBrand->id], 'viewer');
        $response = $this->actingAs($viewer)->withSession(['brand_id' => $first->id])->get('/team?role=viewer');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('members.total', 1)
            ->where('invitations.total', 1)->where('invitations.data.0.email', 'visible@example.test'));
        $payload = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('hidden@example.test', $payload);
        $this->assertStringNotContainsString('foreign@example.test', $payload);
        $this->assertStringNotContainsString($second->id, $payload);
        $this->assertStringNotContainsString('token_hash', $payload);
        $this->actingAs($editor)->withSession(['brand_id' => $first->id])->get('/team?role=viewer')->assertForbidden();
    }
}
