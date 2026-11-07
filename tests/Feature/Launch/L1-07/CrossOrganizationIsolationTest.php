<?php

namespace Tests\Feature\Launch\L1_07;

use App\Models\Membership;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CrossOrganizationIsolationTest extends TenancyTestCase
{
    /**
     * SP-L1-07: before this change, Cabinet always resolved the single installation
     * default organization; a second organization's owner could not use the cabinet at all.
     */
    public function test_each_owners_tenant_route_resolves_their_own_organization_not_the_installation_default(): void
    {
        [$ownerA, $orgA, $brandA] = $this->cabinet();
        [$ownerB, $orgB, $brandB] = $this->cabinet(false);

        $this->actingAs($ownerB);
        $this->getJson('/tenancy-test/brands/'.$brandB->id)->assertOk();
        $this->getJson('/tenancy-test/brands/'.$brandA->id)->assertNotFound();
        $this->getJson('/tenancy-test/page')->assertOk();

        $this->actingAs($ownerA);
        $this->getJson('/tenancy-test/brands/'.$brandA->id)->assertOk();
        $this->getJson('/tenancy-test/brands/'.$brandB->id)->assertNotFound();
    }

    public function test_a_member_without_any_membership_in_the_default_organization_is_denied_not_redirected_there(): void
    {
        [, $orgB, $brandB] = $this->cabinet(false);
        [$viewer] = $this->member($orgB, 'viewer', [$brandB->id]);

        $this->actingAs($viewer);
        $this->getJson('/tenancy-test/brands/'.$brandB->id)->assertOk();
        $this->assertSame(1, Membership::withoutGlobalScopes()->where('user_id', $viewer->id)->count());
    }
}
