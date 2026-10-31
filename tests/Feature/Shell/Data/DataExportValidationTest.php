<?php

namespace Tests\Feature\Shell\Data;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DataExportValidationTest extends TenancyTestCase
{
    public function test_unknown_module_and_invalid_period_are_rejected_without_creating_an_export(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);

        $this->from('/settings/data')->post('/settings/data/exports', [
            'module' => 'unknown-module', 'from' => '2026-12-31', 'to' => '2026-01-01',
        ])->assertSessionHasErrors();

        self::assertSame(0, DB::table('data_exports')->count());
    }

    public function test_guest_cannot_create_or_download_export(): void
    {
        $this->post('/settings/data/exports', ['module' => 'cabinet'])->assertRedirect('/login');
        $this->get('/settings/data/exports/01J00000000000000000000000/download')->assertRedirect('/login');
    }

    public function test_no_organization_delete_route_is_added(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);

        $this->delete('/company')->assertStatus(405);
        $this->assertDatabaseHas('organizations', ['id' => $org->id]);
    }

    public function test_member_can_change_only_own_marketing_consent_and_it_is_audited(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$member] = $this->member($org, 'member', [$brand->id]);
        [$other] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id]);

        $this->patch('/settings/data/consents', ['marketing_opt_in' => true])
            ->assertRedirect('/settings/data')->assertSessionHasNoErrors();
        self::assertTrue((bool) $member->fresh()->marketing_opt_in);
        self::assertFalse((bool) $other->fresh()->marketing_opt_in);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'subject_type' => 'user',
            'subject_id' => $member->id, 'action' => 'data.marketing_consent.changed']);
    }

    public function test_viewer_can_change_only_own_marketing_consent_through_web_route(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$other] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);

        $this->patch('/settings/data/consents', ['marketing_opt_in' => true])
            ->assertRedirect('/settings/data')->assertSessionHasNoErrors();
        self::assertTrue((bool) $viewer->fresh()->marketing_opt_in);
        self::assertFalse((bool) $other->fresh()->marketing_opt_in);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'subject_type' => 'user',
            'subject_id' => $viewer->id, 'action' => 'data.marketing_consent.changed']);
    }
}
