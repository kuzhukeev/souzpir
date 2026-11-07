<?php

namespace Tests\Feature\Launch\L1_07;

use App\Models\Brand;
use App\Models\Organization;
use App\Shell\Team\Models\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrgCreateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_organization_brand_plan_and_owner_invitation(): void
    {
        $this->assertSame(0, Artisan::call('org:create', ['name' => 'Alpha Studio', 'owner_email' => 'Alpha-Owner@Example.Test']));
        $output = Artisan::output();
        self::assertSame(1, preg_match('~/invitations/open#([A-Za-z0-9]{64})~', $output, $match));

        $organization = Organization::where('name', 'Alpha Studio')->firstOrFail();
        $this->assertSame('active', $organization->status);
        $this->assertNull($organization->owner_user_id);
        $brand = Brand::withoutGlobalScopes()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('Alpha Studio', $brand->name);
        $invitation = Invitation::withoutGlobalScopes()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('alpha-owner@example.test', $invitation->email);
        $this->assertSame(hash('sha256', $match[1]), $invitation->token_hash);
        $this->assertTrue($invitation->settings['designated_owner']);
        $this->assertSame('Система', DB::table('users')->where('id', $invitation->invited_by)->value('name'));
        $plan = DB::table('organization_plan')->where('organization_id', $organization->id)->first();
        $this->assertNotNull($plan);
        $this->assertSame(DB::table('plans')->where('code', 'start')->value('id'), $plan->plan_id);
    }

    public function test_reuses_the_same_system_inviter_across_organizations(): void
    {
        Artisan::call('org:create', ['name' => 'Omega One', 'owner_email' => 'omega1@example.test']);
        Artisan::call('org:create', ['name' => 'Omega Two', 'owner_email' => 'omega2@example.test']);
        $inviters = Invitation::withoutGlobalScopes()->whereIn('email', ['omega1@example.test', 'omega2@example.test'])->pluck('invited_by');
        $this->assertCount(1, $inviters->unique());
        $this->assertSame(1, DB::table('users')->where('email', 'system.org-create@cabinet.internal')->count());
    }

    public function test_rejects_unknown_plan_code(): void
    {
        $this->assertSame(1, Artisan::call('org:create', ['name' => 'Beta', 'owner_email' => 'beta@example.test', '--plan' => 'does-not-exist']));
        $this->assertDatabaseMissing('organizations', ['name' => 'Beta']);
    }

    public function test_rejects_duplicate_organization_name(): void
    {
        Artisan::call('org:create', ['name' => 'Gamma', 'owner_email' => 'gamma1@example.test']);
        $this->assertSame(1, Artisan::call('org:create', ['name' => 'Gamma', 'owner_email' => 'gamma2@example.test']));
        $this->assertSame(1, Organization::where('name', 'Gamma')->count());
    }

    public function test_rejects_repeat_for_the_same_pending_owner_email(): void
    {
        Artisan::call('org:create', ['name' => 'Delta', 'owner_email' => 'delta@example.test']);
        $this->assertSame(1, Artisan::call('org:create', ['name' => 'Delta Two', 'owner_email' => 'delta@example.test']));
        $this->assertDatabaseMissing('organizations', ['name' => 'Delta Two']);
    }
}
