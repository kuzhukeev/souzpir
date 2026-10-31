<?php

namespace Tests\Feature\Shell\Profile;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TestNotificationsTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['features.E0-04.defaults.external_delivery' => false]);
    }

    public function test_cabinet_test_is_persisted_once_audited_and_visible_only_to_its_actor_and_brand(): void
    {
        [, $org, $brand, $otherBrand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id, $otherBrand->id]);
        [$other] = $this->member($org, 'admin');
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $data = ['channel' => 'cabinet', 'request_key' => (string) Str::uuid(), 'user_id' => $other->id];
        $this->post('/profile/notifications/test', $data)->assertRedirect('/profile/notifications');
        $this->post('/profile/notifications/test', $data)->assertRedirect();
        $this->assertDatabaseCount('profile_test_notifications', 1);
        $this->assertDatabaseHas('profile_test_notifications', ['user_id' => $viewer->id, 'brand_id' => $brand->id, 'status' => 'sent']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'profile.notification_test.requested')->count());
        $this->get('/profile/notifications')->assertViewHas('page', fn ($page) => count($page['props']['notificationSettings']['testNotifications']) === 1);
        $this->withSession(['brand_id' => $otherBrand->id])->get('/profile/notifications')->assertViewHas('page', fn ($page) => $page['props']['notificationSettings']['testNotifications'] === []);
        $this->actingAs($other)->withSession(['brand_id' => $brand->id])->get('/profile/notifications')->assertViewHas('page', fn ($page) => $page['props']['notificationSettings']['testNotifications'] === []);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_unconfigured_external_channels_are_rejected_without_pending_jobs_or_false_delivery(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        foreach (['email', 'telegram'] as $channel) {
            $this->postJson('/profile/notifications/test', ['channel' => $channel, 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('channel');
        }
        $this->assertDatabaseCount('profile_test_notifications', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_request_rejects_bad_values_and_idempotency_key_reuse_for_a_different_channel(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/profile/notifications/test', ['channel' => 'webhook', 'request_key' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors(['channel', 'request_key']);
        $key = (string) Str::uuid();
        $this->post('/profile/notifications/test', ['channel' => 'cabinet', 'request_key' => $key])->assertRedirect();
        $this->postJson('/profile/notifications/test', ['channel' => 'email', 'request_key' => $key])->assertConflict();
        $this->assertDatabaseCount('profile_test_notifications', 1);
    }

    public function test_audit_failure_rolls_back_the_cabinet_test(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->app->instance(AuditWriter::class, new class implements AuditWriter
        {
            public function record(TenantContext $context, AuditEntry $entry): void
            {
                throw new \RuntimeException('audit unavailable');
            }
        });
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->post('/profile/notifications/test', ['channel' => 'cabinet', 'request_key' => (string) Str::uuid()])->assertStatus(500);
        $this->assertDatabaseCount('profile_test_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_stale_or_spoofed_brand_cannot_receive_a_test(): void
    {
        [$user, , $brand, $otherBrand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $otherBrand->id)->postJson('/profile/notifications/test', ['channel' => 'cabinet', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('profile_test_notifications', 0);
    }
}
