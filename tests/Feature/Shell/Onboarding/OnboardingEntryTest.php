<?php

namespace Tests\Feature\Shell\Onboarding;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class OnboardingEntryTest extends TenancyTestCase
{
    public function test_owner_without_brands_is_directed_to_onboarding(): void
    {
        [$owner, $organization] = $this->cabinet();
        DB::table('brands')->where('organization_id', $organization->id)->delete();

        $this->actingAs($owner)->get('/auth/ready')->assertRedirect('/start');
        $this->get('/start')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shell/Onboarding/Start', false)->where('initial.completed', false));
    }

    public function test_only_roles_with_brand_access_are_offered_journal(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        [$admin] = $this->member($organization, 'admin', [$brand->id]);
        [$editor] = $this->member($organization, 'member', [$brand->id]);
        [$manager] = $this->member($organization, 'member', [$brand->id], ['sales_manager' => true]);
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        [$noBrand] = $this->member($organization, 'member', []);
        foreach ([[$owner, true], [$admin, true], [$editor, true], [$manager, false], [$viewer, false], [$noBrand, false]] as [$user, $allowed]) {
            $this->flushSession()->actingAs($user)->get('/auth/ready')->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Shell/Auth/Ready', false)->where('canOpenJournal', $allowed));
        }
    }
}
