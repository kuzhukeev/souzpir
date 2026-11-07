<?php

namespace Tests\Feature\Seller\Channels\Planned;

use App\Shell\Notifications\ExternalDelivery;
use App\Shell\Notifications\FakeTimedDelivery;
use App\Shell\Notifications\FakeTimingTransport;
use App\Shell\Notifications\NotificationProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class ContactReminderTest extends LimitsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['runtime.notification_driver' => 'fake', 'features.E0-04.defaults.external_delivery' => false]);
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00 UTC'));
    }

    public function test_setting_is_saved_per_brand_and_checks_access_and_validation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->putJson('/seller/planned/reminders', ['enabled' => 'invalid'])->assertUnprocessable();
        $this->putJson('/seller/planned/reminders', ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
        $this->get('/seller/integrations/calendar')->assertInertia(fn (Assert $p) => $p->where('planned.reminders.enabled', true));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->withHeader('X-Brand-Id', $other->id)->get('/seller/integrations/calendar')
            ->assertInertia(fn (Assert $p) => $p->where('planned.reminders.enabled', false));
        [$member] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)
            ->putJson('/seller/planned/reminders', ['enabled' => true])->assertForbidden();
    }

    public function test_due_reminder_is_addressed_once_and_rechecks_before_fake_delivery(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id]);
        DB::table('brands')->where('id', $brand->id)->update(['timezone' => 'Europe/Moscow']);
        DB::table('seller_contact_reminder_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'assigned_user_id' => $manager->id, 'state' => json_encode(['slots' => ['next_contact_at' => '2026-11-07 15:00']]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'user_id' => $manager->id, 'event_key' => 'seller.contact.reminder', 'channel' => 'email', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('seller:contact-reminders')->assertSuccessful();
        $this->artisan('seller:contact-reminders')->assertSuccessful();
        $this->assertDatabaseCount('seller_contact_reminder_events', 1);
        $event = DB::table('seller_contact_reminder_events')->first();
        app(NotificationProcessor::class)->process($event->id);
        $this->assertDatabaseHas('notification_deliveries', ['event_id' => $event->id, 'user_id' => $manager->id, 'channel' => 'email', 'status' => 'pending']);
        $this->assertDatabaseMissing('notification_deliveries', ['event_id' => $event->id, 'user_id' => $owner->id]);
        config(['runtime.notification_driver' => 'live', 'features.E0-04.defaults.external_delivery' => true]);
        $email = DB::table('notification_deliveries')->where('event_id', $event->id)->where('channel', 'email')->value('id');
        app(ExternalDelivery::class)->send($email);
        $this->assertDatabaseHas('notification_deliveries', ['id' => $email, 'status' => 'failed']);
        DB::table('notification_deliveries')->where('id', $email)->update(['status' => 'pending']);
        config(['runtime.notification_driver' => 'fake', 'features.E0-04.defaults.external_delivery' => false]);
        DB::table('conversations')->where('id', $id)->update(['assigned_user_id' => $owner->id]);
        $this->mock(FakeTimingTransport::class)->shouldReceive('send')->never();
        app(FakeTimedDelivery::class)->drain();
        $this->assertDatabaseHas('notification_deliveries', ['event_id' => $event->id, 'user_id' => $manager->id, 'status' => 'pending']);
    }

    public function test_fake_mail_obeys_opt_in_quiet_hours_and_current_date(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brands')->where('id', $brand->id)->update(['timezone' => 'UTC']);
        DB::table('seller_contact_reminder_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'assigned_user_id' => $owner->id,
            'state' => json_encode(['slots' => ['next_contact_at' => '2026-11-07T11:00Z']]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'user_id' => $owner->id, 'event_key' => 'seller.contact.reminder', 'channel' => 'email', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('profile_notification_timing_drafts')->insert(['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id,
            'quiet_start' => '11:00', 'quiet_end' => '13:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('seller:contact-reminders')->assertSuccessful();
        $event = DB::table('seller_contact_reminder_events')->first();
        app(NotificationProcessor::class)->process($event->id);
        $transport = $this->mock(FakeTimingTransport::class);
        $transport->shouldReceive('send')->once()->with($org->id, $brand->id, $owner->id, 'email', ['Время связаться с клиентом']);
        app(FakeTimedDelivery::class)->drain();
        $this->assertDatabaseHas('notification_deliveries', ['event_id' => $event->id, 'channel' => 'email', 'status' => 'pending']);
        $this->travelTo(CarbonImmutable::parse('2026-11-07 13:00 UTC'));
        app(FakeTimedDelivery::class)->drain();
        $this->assertDatabaseHas('notification_deliveries', ['event_id' => $event->id, 'channel' => 'email', 'status' => 'sent']);
    }

    public function test_invalid_or_future_dates_and_personal_opt_out_do_not_send_mail(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('seller_contact_reminder_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['tomorrow', '2026-11-08T12:00Z', '2026-11-07T11:00Z'] as $value) {
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'assigned_user_id' => $owner->id, 'state' => json_encode(['slots' => ['next_contact_at' => $value]]), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->artisan('seller:contact-reminders')->assertSuccessful();
        $this->assertDatabaseCount('seller_contact_reminder_events', 1);
        $event = DB::table('seller_contact_reminder_events')->first();
        app(NotificationProcessor::class)->process($event->id);
        $this->assertDatabaseMissing('notification_deliveries', ['event_id' => $event->id, 'channel' => 'email']);
        $this->mock(FakeTimingTransport::class)->shouldReceive('send')->never();
        app(FakeTimedDelivery::class)->drain();
    }

    public function test_changed_date_or_revoked_access_cancels_old_delivery(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$manager, $membership] = $this->member($org, 'member', [$brand->id]);
        DB::table('seller_contact_reminder_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $conversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'assigned_user_id' => $manager->id, 'state' => json_encode(['slots' => ['next_contact_at' => '2026-11-07T11:00Z']]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'user_id' => $manager->id, 'event_key' => 'seller.contact.reminder', 'channel' => 'email', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('seller:contact-reminders')->assertSuccessful();
        $event = DB::table('seller_contact_reminder_events')->first();
        DB::table('conversations')->where('id', $conversation)->update(['state' => json_encode(['slots' => ['next_contact_at' => '2026-11-08T11:00Z']])]);
        app(NotificationProcessor::class)->process($event->id);
        $this->assertDatabaseMissing('notification_deliveries', ['event_id' => $event->id]);
        DB::table('conversations')->where('id', $conversation)->update(['state' => json_encode(['slots' => ['next_contact_at' => '2026-11-07T10:00Z']])]);
        $this->artisan('seller:contact-reminders')->assertSuccessful();
        $second = DB::table('seller_contact_reminder_events')->where('id', '!=', $event->id)->first();
        DB::table('memberships')->where('id', $membership->id)->update(['status' => 'disabled']);
        app(NotificationProcessor::class)->process($second->id);
        $this->assertDatabaseMissing('notification_deliveries', ['event_id' => $second->id]);
    }
}
