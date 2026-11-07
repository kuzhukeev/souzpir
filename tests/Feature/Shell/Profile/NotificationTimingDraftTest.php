<?php

namespace Tests\Feature\Shell\Profile;

use App\Shell\Audit\Contracts\AuditWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Tenancy\TenancyTestCase;

final class NotificationTimingDraftTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function settings(): array
    {
        $this->flushHeaders();

        return $this->get('/profile/notifications')->assertOk()->viewData('page')['props']['notificationSettings'];
    }

    public function test_viewer_can_save_read_and_clear_a_draft_without_enabling_delivery(): void
    {
        [, $org, $brand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $settings = $this->settings();
        self::assertTrue($settings['timingAvailable']);
        self::assertTrue($settings['timingDraftEditable']);
        self::assertSame(['quiet_start' => null, 'quiet_end' => null, 'digest_time' => null], $settings['timingDraft']);
        $values = ['quiet_start' => '22:30', 'quiet_end' => '07:15', 'digest_time' => '08:45'];
        $this->withHeader('X-Brand-Revision', $settings['timingRevision'])->put('/profile/notifications/timing', $values)
            ->assertRedirect('/profile/notifications')->assertSessionHas('status', 'Настройки сохранены для тестовой доставки.');
        $settings = $this->settings();
        self::assertSame($values, $settings['timingDraft']);
        self::assertTrue($settings['timingAvailable']);
        $this->assertDatabaseHas('profile_notification_timing_drafts', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $viewer->id, ...$values]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $viewer->id, 'brand_id' => $brand->id, 'action' => 'profile.notification_timing.draft_updated']);
        $this->withHeader('X-Brand-Revision', $settings['timingRevision'])->put('/profile/notifications/timing', ['quiet_start' => null, 'quiet_end' => null, 'digest_time' => null])->assertRedirect();
        self::assertSame(['quiet_start' => null, 'quiet_end' => null, 'digest_time' => null], $this->settings()['timingDraft']);
        Http::assertNothingSent();
    }

    public function test_drafts_are_scoped_to_current_user_and_brand_and_reject_stale_revision(): void
    {
        [, $org, $a, $b] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$a->id, $b->id]);
        [$other] = $this->member($org, 'viewer', [$a->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $a->id]);
        $aSettings = $this->settings();
        $this->withHeader('X-Brand-Revision', $aSettings['timingRevision'])->put('/profile/notifications/timing', ['quiet_start' => '23:00', 'quiet_end' => '06:00', 'digest_time' => '09:00'])->assertRedirect();
        $this->flushHeaders();
        $this->post('/brand/switch', ['brand_id' => $b->id])->assertRedirect();
        $this->withHeader('X-Brand-Revision', $aSettings['timingRevision'])->putJson('/profile/notifications/timing', ['digest_time' => '12:00'])->assertStatus(409);
        $this->assertDatabaseCount('profile_notification_timing_drafts', 1);
        $bSettings = $this->settings();
        self::assertSame(['quiet_start' => null, 'quiet_end' => null, 'digest_time' => null], $bSettings['timingDraft']);
        $this->withHeader('X-Brand-Revision', $bSettings['timingRevision'])->put('/profile/notifications/timing', ['quiet_start' => null, 'quiet_end' => null, 'digest_time' => '18:30', 'user_id' => $other->id, 'brand_id' => $a->id])->assertRedirect();
        $this->assertDatabaseHas('profile_notification_timing_drafts', ['brand_id' => $b->id, 'user_id' => $viewer->id, 'digest_time' => '18:30']);
        $this->assertDatabaseHas('profile_notification_timing_drafts', ['brand_id' => $a->id, 'user_id' => $viewer->id, 'digest_time' => '09:00']);
        $this->actingAs($other)->withSession(['brand_id' => $a->id]);
        self::assertSame(['quiet_start' => null, 'quiet_end' => null, 'digest_time' => null], $this->settings()['timingDraft']);
        $this->assertDatabaseMissing('profile_notification_timing_drafts', ['user_id' => $other->id]);
    }

    public function test_invalid_incomplete_or_unbound_requests_never_save_or_audit(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $revision = $this->settings()['timingRevision'];
        $this->putJson('/profile/notifications/timing', ['digest_time' => '09:00'])->assertStatus(409);
        foreach ([['quiet_start' => '24:00', 'quiet_end' => '06:00'], ['quiet_start' => '22:00'], ['quiet_start' => '09:00', 'quiet_end' => '09:00'], ['digest_time' => '9:00']] as $invalid) {
            $this->withHeader('X-Brand-Revision', $revision)->putJson('/profile/notifications/timing', $invalid)->assertUnprocessable();
        }
        $this->assertDatabaseCount('profile_notification_timing_drafts', 0);
        self::assertSame(0, DB::table('audit_logs')->where('action', 'profile.notification_timing.draft_updated')->count());
    }

    public function test_foreign_organization_brand_is_rejected_by_http_and_database(): void
    {
        [$user, $org, $brand] = $this->cabinet();
        [, , $foreignBrand] = $this->cabinet(false);
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $revision = $this->settings()['timingRevision'];
        $this->withSession(['brand_id' => $foreignBrand->id])->withHeader('X-Brand-Revision', $revision)
            ->putJson('/profile/notifications/timing', ['digest_time' => '09:00'])->assertNotFound();
        $this->assertDatabaseCount('profile_notification_timing_drafts', 0);
        try {
            DB::transaction(fn () => DB::table('profile_notification_timing_drafts')->insert([
                'organization_id' => $org->id, 'brand_id' => $foreignBrand->id, 'user_id' => $user->id,
                'digest_time' => '09:00', 'created_at' => now(), 'updated_at' => now(),
            ]));
            self::fail('The database must reject a brand from another organization.');
        } catch (QueryException $error) {
            self::assertSame('23503', $error->getCode());
        }
        $this->assertDatabaseCount('profile_notification_timing_drafts', 0);
    }

    public function test_missing_draft_schema_is_unavailable_without_disabling_profile(): void
    {
        [$user, , $brand] = $this->cabinet();
        Schema::drop('profile_notification_timing_drafts');
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $settings = $this->settings();
        self::assertFalse($settings['timingAvailable']);
        self::assertFalse($settings['timingDraftEditable']);
        self::assertNull($settings['timingDraft']);
        $this->withHeader('X-Brand-Revision', $settings['timingRevision'])->putJson('/profile/notifications/timing', ['digest_time' => '09:00'])->assertStatus(503);
        self::assertSame(0, DB::table('audit_logs')->where('action', 'profile.notification_timing.draft_updated')->count());
    }

    public function test_audit_failure_rolls_back_draft_and_sanitizes_the_error(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $revision = $this->settings()['timingRevision'];
        $audit = $this->createStub(AuditWriter::class);
        $audit->method('record')->willThrowException(new \RuntimeException('synthetic-private-audit-detail'));
        $this->app->instance(AuditWriter::class, $audit);
        $this->withoutExceptionHandling();
        try {
            $this->withHeader('X-Brand-Revision', $revision)->put('/profile/notifications/timing', ['digest_time' => '09:00']);
            self::fail('Expected a sanitized exception.');
        } catch (\RuntimeException $error) {
            self::assertSame('Notification timing draft processing failed.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('synthetic-private-audit-detail', $error->getTraceAsString());
        }
        $this->assertDatabaseCount('profile_notification_timing_drafts', 0);
    }
}
