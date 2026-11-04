<?php

namespace Tests\Feature\Shell\Context;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Tenancy\TenancyTestCase;

final class OrganizationSelectE102DTest extends TenancyTestCase
{
    public function test_roles_and_brand_grants_are_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->get('/context/organization')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        DB::table('member_last_logins')->where('user_id', $owner->id)->delete();
        $this->get('/context/organization')->assertOk()->assertInertia(fn ($page) => $page->component('Shell/Context/OrganizationSelect')->where('company.name', $org->name)->where('company.role', 'Владелец')->has('company.brands', 2)->where('company.lastLogin', null));
        foreach ([['admin', [], 'Администратор'], ['member', [], 'Редактор'], ['member', ['sales_manager' => true], 'Менеджер'], ['viewer', [], 'Наблюдатель']] as [$role, $settings, $label]) {
            [$user] = $this->member($org, $role, [$brand->id], $settings);
            $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
            $this->get('/context/organization')->assertOk()->assertInertia(fn ($page) => $page->where('company.role', $label)->has('company.brands', 1)->where('company.brands.0.id', $brand->id));
        }
        [$disabled, $membership] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($disabled)->withSession(['brand_id' => $brand->id]);
        $this->get('/context/organization')->assertOk();
        DB::table('memberships')->where('id', $membership->id)->update(['status' => 'disabled']);
        $this->get('/context/organization')->assertForbidden();
    }
}
