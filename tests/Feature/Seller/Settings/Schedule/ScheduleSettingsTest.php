<?php

namespace Tests\Feature\Seller\Settings\Schedule;

use App\Seller\Settings\Schedule\AssignmentCandidates;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ScheduleSettingsTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_settings_are_brand_scoped_and_validate_input(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $week = array_fill(0, 7, ['enabled' => true, 'start' => '09:00', 'end' => '18:00', 'behavior' => 'quiet']);
        $this->post('/seller/settings/schedule', ['mode' => 'suggest', 'week' => $week, 'holidays' => ['2026-11-03']])->assertRedirect();
        $this->get('/seller/settings')->assertOk()->assertInertia(fn ($page) => $page->where('settings.mode', 'suggest')->where('settings.week.0.behavior', 'quiet'));
        $this->post('/seller/settings/assignment', ['method' => 'fixed', 'fixed' => $owner->id, 'notify_target' => 'owner',
            'members' => [['id' => $owner->id, 'enabled' => true, 'max_active' => null, 'hours' => ['start' => '09:00', 'end' => '18:00'], 'channels' => ['widget']]]])->assertRedirect();
        $this->get('/seller/settings?tab=assignment')->assertOk()->assertInertia(fn ($page) => $page->where('settings.assignment.members.0.channels', ['widget'])->where('settings.assignment.members.0.hours.start', '09:00'));
        $this->post('/seller/settings/schedule', ['mode' => 'wrong', 'week' => [], 'holidays' => []])->assertSessionHasErrors('mode');
        $this->post('/seller/settings/assignment', ['method' => 'fixed', 'fixed' => (string) Str::ulid(), 'notify_target' => 'owner', 'members' => []])->assertSessionHasErrors('members');
        $this->post('/seller/settings/assignment', ['method' => 'fixed', 'fixed' => $owner->id, 'notify_target' => 'owner',
            'members' => [['id' => $owner->id, 'enabled' => true, 'max_active' => null, 'hours' => ['start' => '18:00', 'end' => '09:00'], 'channels' => []]]])->assertSessionHasErrors('members');
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.schedule.settings.updated']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/settings')->assertOk()->assertInertia(fn ($page) => $page->where('settings.mode', 'autonomous')->has('settings.week', 0));
    }

    public function test_viewer_cannot_change_settings_and_assignment_checks_brand_membership(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        [$viewer, $viewerMember] = $this->member($org, 'viewer', [$brand->id]);
        [$manager] = $this->member($org, 'member', [$other->id]);
        $context = new TenantContext($org->id, $brand->id, $owner->id, null, source: 'job');
        $choice = app(AssignmentCandidates::class)->select($context, ['method' => 'round_robin', 'members' => [['id' => $viewer->id, 'enabled' => true, 'max_active' => null], ['id' => $manager->id, 'enabled' => true, 'max_active' => null]]]);
        self::assertNull($choice);
        $eligible = ['method' => 'round_robin', 'members' => [['id' => $owner->id, 'enabled' => true, 'max_active' => 1]]];
        self::assertSame($owner->id, app(AssignmentCandidates::class)->select($context, $eligible));
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'assigned_user_id' => $owner->id, 'stage' => 'need', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        self::assertNull(app(AssignmentCandidates::class)->select($context, $eligible));
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/settings')->assertForbidden();
        $this->post('/seller/settings/schedule', ['mode' => 'off', 'week' => [], 'holidays' => []])->assertForbidden();
    }

    public function test_assignment_select_honors_member_channel_and_hours_filters(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $context = new TenantContext($org->id, $brand->id, $owner->id, null, source: 'job');
        $settings = ['method' => 'round_robin', 'members' => [['id' => $owner->id, 'enabled' => true, 'max_active' => null, 'channels' => ['telegram']]]];
        self::assertNull(app(AssignmentCandidates::class)->select($context, $settings, null, 'widget'));
        self::assertSame($owner->id, app(AssignmentCandidates::class)->select($context, $settings, null, 'telegram'));
        self::assertSame($owner->id, app(AssignmentCandidates::class)->select($context, $settings));
        $hours = ['method' => 'round_robin', 'members' => [['id' => $owner->id, 'enabled' => true, 'max_active' => null, 'hours' => ['start' => '09:00', 'end' => '18:00']]]];
        self::assertNull(app(AssignmentCandidates::class)->select($context, $hours, null, null, CarbonImmutable::parse('2026-11-02 20:00:00+00'), 'Europe/Moscow'));
        self::assertSame($owner->id, app(AssignmentCandidates::class)->select($context, $hours, null, null, CarbonImmutable::parse('2026-11-02 10:00:00+00'), 'Europe/Moscow'));
    }
}
