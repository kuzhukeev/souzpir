<?php

namespace Tests\Feature\Shell\Profile;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\NotificationProcessor;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProfileSettingsTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function modules(object $org, object $brand): void
    {
        foreach (['seller', 'vision'] as $module) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => $module, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_profile_changes_only_self_and_keeps_identity_fields_read_only(): void
    {
        [$user,$org,$brand] = $this->cabinet();
        [$other] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->put('/profile', ['name' => 'Новое имя', 'phone' => '+7 (999) 111-22-33', 'timezone' => 'Asia/Tokyo', 'language' => 'ru', 'user_id' => $other->id, 'email' => 'stolen@example.test', 'role' => 'admin'])->assertRedirect('/profile');
        $this->assertSame('Новое имя', $user->fresh()->name);
        $this->assertSame('Test actor', $other->fresh()->name);
        $this->assertNotSame('stolen@example.test', $user->fresh()->email);
        $this->assertDatabaseHas('profile_preferences', ['user_id' => $user->id, 'timezone' => 'Asia/Tokyo']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'profile.updated']);
        $this->get('/profile')->assertOk()->assertViewHas('page', fn ($page) => $page['props']['profile']['effective_timezone'] === 'Asia/Tokyo');
    }

    public function test_viewer_can_save_personal_profile_without_brand_write(): void
    {
        [, $org,$brand] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->put('/profile', ['name' => 'Зритель', 'phone' => null, 'timezone' => null, 'language' => 'ru'])->assertRedirect('/profile');
        $this->assertSame('Зритель', $viewer->fresh()->name);
    }

    public function test_profile_validates_language_timezone_and_name(): void
    {
        [$user,,$brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->putJson('/profile', ['name' => '  ', 'timezone' => 'Mars/Olympus', 'language' => 'en'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'timezone', 'language']);
        $this->assertDatabaseCount('profile_preferences', 0);
    }

    public function test_null_timezone_follows_brand_default(): void
    {
        [$user,,$brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->get('/profile')->assertViewHas('page', fn ($p) => $p['props']['profile']['timezone'] === null && $p['props']['profile']['effective_timezone'] === $brand->fresh()->timezone);
    }

    public function test_profile_audit_failure_rolls_back_storage(): void
    {
        [$user,,$brand] = $this->cabinet();
        $this->app->instance(AuditWriter::class, new class implements AuditWriter
        {
            public function record(TenantContext $context, AuditEntry $entry): void
            {
                throw new \RuntimeException('audit unavailable');
            }
        });
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->put('/profile', ['name' => 'Changed', 'timezone' => 'UTC', 'language' => 'ru'])->assertStatus(500);
        $this->assertSame('Test actor', $user->fresh()->name);
        $this->assertDatabaseCount('profile_preferences', 0);
    }

    public function test_private_avatar_is_normalized_and_not_visible_to_other_user(): void
    {
        Storage::fake('local');
        [$user,$org,$brand] = $this->cabinet();
        [$other] = $this->member($org, 'admin');
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('photo.jpg', 40, 40)])->assertRedirect('/profile');
        $path = DB::table('profile_preferences')->where('user_id', $user->id)->value('avatar_path');
        Storage::disk('local')->assertExists($path);
        $this->get('/profile/avatar')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->flushSession()->actingAs($other)->withSession(['brand_id' => $brand->id])->get('/profile/avatar')->assertNotFound();
        $this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('avatar');
    }

    public function test_catalog_has_seven_seller_six_vision_three_cabinet_events_and_real_defaults(): void
    {
        [$user,$org,$brand] = $this->cabinet();
        $this->modules($org, $brand);
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $response = $this->get('/profile/notifications')->assertOk();
        $settings = $response->viewData('page')['props']['notificationSettings'];
        $this->assertCount(16, $settings['events']);
        $this->assertCount(7, array_filter($settings['events'], fn ($e) => $e['group'] === 'seller'));
        $this->assertCount(6, array_filter($settings['events'], fn ($e) => $e['group'] === 'vision'));
        $this->assertCount(3, array_filter($settings['events'], fn ($e) => $e['group'] === 'shell'));
        foreach ($settings['events'] as $event) {
            $this->assertSame(['cabinet' => true, 'email' => false, 'telegram' => false], $event['channels']);
        }
        $this->assertFalse($settings['telegram']['connected']);
        $this->assertFalse($settings['timingAvailable']);
    }

    public function test_disabled_modules_are_hidden_and_cannot_be_written(): void
    {
        [$user,,$brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->get('/profile/notifications')->assertViewHas('page', fn ($p) => count($p['props']['notificationSettings']['events']) === 3);
        $this->putJson('/profile/notifications', ['changes' => [['event' => 'seller.promise.overdue', 'channel' => 'cabinet', 'enabled' => false]]])->assertUnprocessable();
        $this->assertDatabaseCount('notification_settings', 0);
    }

    public function test_complete_expanded_event_matrix_can_be_saved(): void
    {
        [$user, $org, $brand] = $this->cabinet();
        $this->modules($org, $brand);
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $events = $this->get('/profile/notifications')->assertOk()->viewData('page')['props']['notificationSettings']['events'];
        $changes = [];
        foreach ($events as $event) {
            foreach (['cabinet', 'email', 'telegram'] as $channel) {
                $changes[] = ['event' => $event['key'], 'channel' => $channel, 'enabled' => $channel === 'cabinet'];
            }
        }
        self::assertCount(48, $changes);
        $this->put('/profile/notifications', ['changes' => $changes])->assertRedirect('/profile/notifications')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notification_settings', ['user_id' => $user->id, 'brand_id' => $brand->id, 'event_key' => 'shell.plan_request.created', 'channel' => 'cabinet', 'enabled' => true]);
        $this->assertDatabaseCount('notification_settings', 48);
    }

    public function test_matrix_updates_are_atomic_and_user_brand_scoped(): void
    {
        [$user,$org,$brand,$second] = $this->cabinet();
        $this->modules($org, $brand);
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $change = ['event' => 'seller.conversation.handed_off', 'channel' => 'cabinet', 'enabled' => false];
        $this->put('/profile/notifications', ['changes' => [$change], 'user_id' => 'fake', 'brand_id' => $second->id])->assertRedirect('/profile/notifications');
        $this->assertDatabaseHas('notification_settings', ['user_id' => $user->id, 'brand_id' => $brand->id, 'event_key' => $change['event'], 'channel' => 'cabinet', 'enabled' => false]);
        $this->putJson('/profile/notifications', ['changes' => [[...$change, 'enabled' => true], ['event' => 'seller.promise.overdue', 'channel' => 'telegram', 'enabled' => true]]])->assertUnprocessable();
        $this->assertDatabaseHas('notification_settings', ['user_id' => $user->id, 'event_key' => $change['event'], 'enabled' => false]);
    }

    public function test_saved_opt_out_affects_real_notification_processor(): void
    {
        [$user,$org,$brand] = $this->cabinet();
        $this->modules($org, $brand);
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->put('/profile/notifications', ['changes' => [['event' => 'seller.conversation.handed_off', 'channel' => 'cabinet', 'enabled' => false]]])->assertRedirect();
        $event = new DomainEvent(['event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'seller.conversation.handed_off', 'occurred_at' => now()->toIso8601String(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'actor_id' => $user->id, 'aggregate' => ['type' => 'conversation', 'id' => (string) Str::ulid(), 'version' => 1], 'data' => ['reason' => 'want_human', 'task_id' => (string) Str::ulid()]]);
        DB::table('outbox_events')->insert(['id' => $event->envelope['event_id'], 'organization_id' => $org->id, 'brand_id' => $brand->id, 'envelope' => json_encode($event->envelope), 'created_at' => now()]);
        app(NotificationProcessor::class)->process($event->envelope['event_id']);
        $this->assertDatabaseCount('cabinet_notifications', 0);
        $this->assertDatabaseHas('consumed_events', ['event_id' => $event->envelope['event_id']]);
        Http::assertNothingSent();
    }

    public function test_foreign_membership_and_unverified_user_cannot_change_profile(): void
    {
        [$owner,$org,$brand] = $this->cabinet();
        $foreign = $this->user();
        $this->actingAs($foreign)->withSession(['brand_id' => $brand->id])->putJson('/profile', ['name' => 'No', 'language' => 'ru'])->assertForbidden();
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->putJson('/profile', ['name' => 'No', 'language' => 'ru'])->assertForbidden();
    }

    public function test_personal_boundary_rejects_stale_foreign_brand_header_and_disabled_membership(): void
    {
        [$user, $org, $brand, $second, $member] = $this->cabinet();
        [, , $foreignBrand] = $this->cabinet(false);
        $payload = ['name' => 'Not saved', 'language' => 'ru'];
        $this->actingAs($user)->withSession(['brand_id' => $foreignBrand->id])->putJson('/profile', $payload)->assertNotFound();
        $this->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $second->id)->putJson('/profile', $payload)->assertNotFound();
        $this->flushHeaders();
        DB::table('brands')->where('id', $brand->id)->update(['status' => 'archived']);
        $this->putJson('/profile', $payload)->assertNotFound();
        $this->withSession(['brand_id' => $second->id]);
        DB::table('memberships')->where('id', $member->id)->update(['status' => 'disabled']);
        $this->putJson('/profile', $payload)->assertForbidden();
        $this->assertSame('Test actor', $user->fresh()->name);
        $this->assertDatabaseCount('profile_preferences', 0);
    }

    public function test_avatar_does_not_trust_another_user_path_in_own_preference_row(): void
    {
        Storage::fake('local');
        [$user, $org, $brand] = $this->cabinet();
        [$other] = $this->member($org, 'admin');
        $foreignPath = 'users/'.$other->id.'/profile/'.str_repeat('a', 32).'.png';
        Storage::disk('local')->put($foreignPath, 'private image');
        DB::table('profile_preferences')->insert(['user_id' => $user->id, 'avatar_path' => $foreignPath, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->get('/profile/avatar')->assertNotFound();
        $this->delete('/profile/avatar')->assertRedirect('/profile');
        Storage::disk('local')->assertExists($foreignPath);
    }

    public function test_timezone_only_change_does_not_claim_name_or_phone_changed(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id])->put('/profile', ['name' => $user->name, 'phone' => null, 'timezone' => 'Asia/Tokyo', 'language' => 'ru'])->assertRedirect();
        $after = json_decode(DB::table('audit_logs')->where('action', 'profile.updated')->value('after'), true);
        $this->assertFalse($after['name_changed']);
        // phone-related audit fields are redacted by the shared audit contract.
        $this->assertSame('[REDACTED]', $after['phone_changed']);
    }
}
