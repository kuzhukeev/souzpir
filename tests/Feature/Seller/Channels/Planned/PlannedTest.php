<?php

namespace Tests\Feature\Seller\Channels\Planned;

use App\Shell\Audit\Contracts\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class PlannedTest extends LimitsTestCase
{
    public function test_planned_pages_are_honest_and_reminders_are_available_in_fake_mode(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        foreach (['/seller/channels/vk' => 'vk', '/seller/channels/whatsapp' => 'whatsapp',
            '/seller/integrations/calendar' => 'calendar', '/seller/integrations/planned' => 'planned'] as $path => $section) {
            $this->get($path)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Seller/Channels/Planned/Index', false)
                ->where('section', $section)->has('planned.items', 8)->where('planned.canManage', true)
                ->where('planned.interestAvailable', true)->where('planned.reminders.available', true)
                ->where('planned.reminders.enabled', false));
        }
    }

    public function test_reminders_toggle_persists_in_fake_mode_and_stays_unavailable_otherwise(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->putJson('/seller/planned/reminders', ['enabled' => true])->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.available', true);
        $this->assertDatabaseHas('seller_contact_reminder_settings', [
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'enabled' => true,
        ]);
        $this->get('/seller/channels/vk')->assertInertia(fn (Assert $p) => $p
            ->where('planned.reminders.available', true)->where('planned.reminders.enabled', true));
        config(['runtime.notification_driver' => 'live']);
        $this->get('/seller/channels/vk')->assertInertia(fn (Assert $p) => $p
            ->where('planned.reminders.available', false)->where('planned.reminders.enabled', false));
        $this->putJson('/seller/planned/reminders', ['enabled' => false])->assertStatus(503);
    }

    public function test_interest_is_scoped_idempotent_and_audited(): void
    {
        [$owner, $org, $brand, $otherBrand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        foreach ([1, 2] as $_) {
            $this->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertOk()
                ->assertJsonPath('data.integration', 'vk')->assertJsonPath('data.interested', true);
        }
        $this->assertDatabaseCount('seller_planned_integration_interests', 1);
        $this->assertDatabaseHas('seller_planned_integration_interests', ['organization_id' => $org->id,
            'brand_id' => $brand->id, 'user_id' => $owner->id, 'integration' => 'vk']);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'seller.planned_interest.created')->count());
        $this->post('/brand/switch', ['brand_id' => $otherBrand->id])->assertRedirect('/auth/ready');
        $this->withHeader('X-Brand-Id', $otherBrand->id)->get('/seller/channels/vk')
            ->assertInertia(fn (Assert $p) => $p->where('planned.items.0.interested', false));
        $this->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertOk();
        $this->assertDatabaseCount('seller_planned_integration_interests', 2);
    }

    public function test_denied_invalid_disabled_and_revoked_paths_do_not_send_or_save(): void
    {
        Http::fake();
        Mail::fake();
        [$owner, $org, $brand] = $this->cabinet();
        [$member] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)
            ->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)
            ->postJson('/seller/planned/interests', ['integration' => 'unknown'])->assertUnprocessable();
        config(['runtime.notification_driver' => null]);
        $this->putJson('/seller/planned/reminders', ['enabled' => true])->assertStatus(503);
        $this->putJson('/seller/planned/reminders', ['enabled' => false])->assertStatus(503);
        config(['runtime.notification_driver' => 'fake']);
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $this->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertUnauthorized();
        $this->assertDatabaseCount('seller_planned_integration_interests', 0);
        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }

    public function test_audit_failure_rolls_back_interest(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->mock(AuditWriter::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertServerError();
        $this->assertDatabaseCount('seller_planned_integration_interests', 0);
    }

    public function test_interests_do_not_cross_actors_or_organizations(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$admin] = $this->member($org, 'admin', [$brand->id]);
        [$foreignOwner, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertOk();
        $this->get('/seller/channels/vk')->assertInertia(fn (Assert $p) => $p->where('planned.items.0.interested', true));

        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->get('/seller/channels/vk')->assertInertia(fn (Assert $p) => $p->where('planned.items.0.interested', false));
        $this->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertOk();
        $this->assertDatabaseCount('seller_planned_integration_interests', 2);
        config(['features.E0-02.defaults.organization_id' => $foreignOrg->id]);
        $this->actingAs($foreignOwner)->withSession(['brand_id' => $foreignBrand->id])->withHeader('X-Brand-Id', $foreignBrand->id);
        $this->get('/seller/channels/vk')->assertInertia(fn (Assert $p) => $p->where('planned.items.0.interested', false));
        $this->assertDatabaseMissing('seller_planned_integration_interests', ['organization_id' => $foreignOrg->id, 'user_id' => $foreignOwner->id]);
    }

    public function test_revoked_brand_does_not_allow_stale_reads_or_writes(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        [$admin, $membership] = $this->member($org, 'admin', [$brand->id]);
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertOk();
        DB::table('memberships')->where('id', $membership->id)->update(['brand_ids' => json_encode([$other->id], JSON_THROW_ON_ERROR)]);
        $this->withHeader('X-Brand-Id', $brand->id)->get('/seller/channels/vk')->assertNotFound();
        $this->postJson('/seller/planned/interests', ['integration' => 'whatsapp'])->assertNotFound();
        $this->assertDatabaseMissing('seller_planned_integration_interests', ['brand_id' => $other->id, 'user_id' => $admin->id]);
    }

    public function test_disabled_module_and_revoked_session_reject_access(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        DB::table('brand_modules')->where('brand_id', $brand->id)->where('module', 'seller')->update(['enabled' => false]);
        $this->get('/seller/channels/vk')->assertNotFound();
        $this->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertNotFound();
        DB::table('brand_modules')->where('brand_id', $brand->id)->where('module', 'seller')->update(['enabled' => true]);
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $this->getJson('/seller/channels/vk')->assertUnauthorized();
        $this->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertUnauthorized();
        $this->assertDatabaseCount('seller_planned_integration_interests', 0);
    }

    public function test_invalid_input_types_cannot_create_an_interest_or_enable_reminders(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        foreach ([null, [], true, 1, 'telegram'] as $value) {
            $this->postJson('/seller/planned/interests', ['integration' => $value])->assertUnprocessable()->assertJsonValidationErrors('integration');
        }
        foreach ([null, [], 'true', 2] as $value) {
            $this->putJson('/seller/planned/reminders', ['enabled' => $value])->assertUnprocessable()->assertJsonValidationErrors('enabled');
        }
        $this->assertDatabaseCount('seller_planned_integration_interests', 0);
    }

    public function test_old_tab_cannot_write_after_another_tab_switches_brand(): void
    {
        [$owner, , $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->withHeader('X-Brand-Id', $brand->id);
        $this->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertNotFound();
        $this->putJson('/seller/planned/reminders', ['enabled' => false])->assertNotFound();
        $this->assertDatabaseCount('seller_planned_integration_interests', 0);
        $this->withHeader('X-Brand-Id', $other->id)->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertOk();
        $this->assertDatabaseHas('seller_planned_integration_interests', ['brand_id' => $other->id, 'user_id' => $owner->id]);
    }

    public function test_missing_brand_header_does_not_allow_mutations(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/planned/interests', ['integration' => 'vk'])->assertNotFound();
        $this->putJson('/seller/planned/reminders', ['enabled' => false])->assertNotFound();
        $this->assertDatabaseCount('seller_planned_integration_interests', 0);
    }
}
