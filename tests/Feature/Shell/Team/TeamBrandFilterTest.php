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

final class TeamBrandFilterTest extends TenancyTestCase
{
    private function invite(Organization $organization, User $actor, string $email, array $brands, string $role = 'viewer', array $settings = []): void
    {
        $this->within($organization, null, fn () => Invitation::create([
            'email' => $email, 'role' => $role, 'settings' => $settings, 'brand_ids' => $brands,
            'token_hash' => hash('sha256', Str::random(64)), 'invited_by' => $actor->id, 'expires_at' => now()->addDay(),
        ]));
    }

    public function test_role_and_brand_are_applied_before_both_paginations(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        for ($i = 0; $i < 27; $i++) {
            $this->member($org, 'member', [$first->id], ['sales_manager' => true]);
            $this->invite($org, $owner, "manager{$i}@example.test", [$first->id], 'member', ['sales_manager' => true]);
        }
        $this->member($org, 'member', [$second->id], ['sales_manager' => true]);
        $this->member($org, 'member', [$first->id]);
        $this->invite($org, $owner, 'other-brand@example.test', [$second->id], 'member', ['sales_manager' => true]);
        $this->invite($org, $owner, 'other-role@example.test', [$first->id], 'member');
        [$foreignOwner, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $this->member($foreignOrg, 'member', [$foreignBrand->id], ['sales_manager' => true]);
        $this->invite($foreignOrg, $foreignOwner, 'foreign@example.test', [$foreignBrand->id], 'member', ['sales_manager' => true]);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $response = $this->get('/team?role=manager&brand='.$first->id.'&page=2&invitation_page=2');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('roleFilter', 'manager')->where('brandFilter', $first->id)
            ->where('members.total', 27)->where('members.current_page', 2)->has('members.data', 2)
            ->where('invitations.total', 27)->where('invitations.current_page', 2)->has('invitations.data', 2));
        $this->get('/team?role=manager&brand='.$second->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 1)->where('invitations.total', 1)->where('invitations.data.0.email', 'other-brand@example.test'));
        $this->get('/team?role=editor&brand='.$first->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 1)->where('invitations.total', 1)->where('invitations.data.0.email', 'other-role@example.test'));
        $this->get('/team?brand='.$foreignBrand->id)->assertNotFound();
        $this->get('/team?brand[]=all')->assertNotFound();
        $this->get('/team?brand=unknown')->assertNotFound();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_brand_access_full_and_restricted_grants_are_enforced_without_disclosure(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', []);
        [$restricted] = $this->member($org, 'admin', [$first->id]);
        [$viewer] = $this->member($org, 'viewer', [$first->id]);
        [$other] = $this->member($org, 'viewer', [$second->id]);
        [$editor] = $this->member($org, 'member', [$first->id]);
        $this->invite($org, $owner, 'all-brands@example.test', [], 'admin');
        $this->invite($org, $owner, 'first@example.test', [$first->id]);
        $this->invite($org, $owner, 'second@example.test', [$second->id]);
        [$foreignOwner, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $this->invite($foreignOrg, $foreignOwner, 'foreign@example.test', [$foreignBrand->id]);
        foreach ([$owner, $admin] as $actor) {
            $response = $this->actingAs($actor)->withSession(['brand_id' => $first->id])->get('/team?brand='.$second->id);
            $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('filterBrands', 2)
                ->where('members.total', 3)->where('invitations.total', 2));
            $emails = collect($response->inertiaProps('members.data'))->pluck('email')->all();
            $this->assertContains($owner->email, $emails);
            $this->assertContains($admin->email, $emails);
            $this->assertContains($other->email, $emails);
            $this->assertNotContains($restricted->email, $emails);
        }
        foreach ([$restricted, $viewer] as $actor) {
            $this->actingAs($actor)->withSession(['brand_id' => $first->id]);
            $this->get('/team?brand='.$second->id)->assertNotFound();
            $this->get('/team?brand='.$foreignBrand->id)->assertNotFound();
            $response = $this->get('/team?brand='.$first->id);
            $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('filterBrands', 1)->where('filterBrands.0.id', $first->id)
                ->where('members.total', 5)->where('invitations.total', 2));
            $payload = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
            foreach ([$second->id, $other->email, 'second@example.test', 'foreign@example.test', $foreignBrand->id, 'token_hash'] as $secret) {
                $this->assertStringNotContainsString($secret, $payload);
            }
        }
        $this->actingAs($editor)->withSession(['brand_id' => $first->id])->get('/team?brand='.$first->id)->assertForbidden();
        DB::table('brands')->where('id', $second->id)->update(['status' => 'archived']);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])->get('/team?brand='.$second->id)->assertNotFound();
    }
}
