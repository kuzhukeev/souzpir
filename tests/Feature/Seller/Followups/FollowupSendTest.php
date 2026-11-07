<?php

namespace Tests\Feature\Seller\Followups;

use App\Seller\Followups\FollowupConsent;
use App\Seller\Followups\FollowupPolicy;
use App\Seller\Followups\FollowupSender;
use App\Seller\Followups\ProcessFollowups;
use App\Seller\Followups\SendFollowup;
use App\Seller\Runtime\Contracts\ChannelDelivery;
use App\Seller\Runtime\Contracts\DeliveryCommand;
use App\Seller\Runtime\Contracts\DeliveryReceipt;
use App\Seller\Runtime\Delivery\FakeChannelDelivery;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FollowupSendTest extends TenancyTestCase
{
    private function fixture($org, $brand, string $reason = 'silence', int $max = 2): array
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brand_seller_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'followup' => json_encode(['first_hours' => 20, 'max_auto' => $max, 'texts' => ['Напоминание']]), 'off_topic' => '{}', 'negative' => '{}', 'tools' => '{}', 'greetings' => '{}', 'phrases' => '{}', 'schedule' => '{}', 'assignment' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $conversation = (string) Str::ulid();
        $candidate = (string) Str::ulid();
        $source = now()->subHours(21);
        $state = $reason === 'next_contact_at' ? ['slots' => ['next_contact_at' => $source->toIso8601String()]] : [];
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'stage' => 'need', 'state' => json_encode($state), 'last_client_message_at' => now()->subHours(23), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $conversation, 'role' => 'agent', 'text' => 'Ответ', 'meta' => '{}', 'created_at' => $source, 'updated_at' => $source]);
        DB::table('seller_followup_candidates')->insert(['id' => $candidate, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $conversation, 'reason' => $reason, 'source_at' => $source, 'sequence' => 1, 'text' => 'Напоминание', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return [$conversation, $candidate, $source];
    }

    public function test_success_marks_agreed_date_and_repeat_does_not_send(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        Http::preventStrayRequests();
        try {
            [, $org, $brand] = $this->cabinet();
            [$conversation, $candidate, $source] = $this->fixture($org, $brand, 'next_contact_at', 1);
            $tenant = new TenantContext($org->id, $brand->id, source: 'job');
            $sender = app(FollowupSender::class);
            self::assertTrue($sender->send($tenant, $candidate, app(FakeChannelDelivery::class), app(FollowupPolicy::class)));
            self::assertFalse($sender->send($tenant, $candidate, app(FakeChannelDelivery::class), app(FollowupPolicy::class)));
            $this->assertDatabaseHas('seller_followup_candidates', ['id' => $candidate, 'status' => 'completed']);
            $this->assertDatabaseHas('conversations', ['id' => $conversation, 'followups_sent' => 1]);
            $state = json_decode(DB::table('conversations')->where('id', $conversation)->value('state'), true);
            self::assertTrue($state['flags']['_followup_done_'.$source->timestamp]);
            $this->assertDatabaseMissing('seller_tasks', ['conversation_id' => $conversation, 'type' => 'stale']);
            self::assertSame(2, DB::table('messages')->where('conversation_id', $conversation)->count());
            Http::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_delivery_failure_foreign_brand_and_changed_conversation_do_not_complete(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        Http::preventStrayRequests();
        try {
            [, $org, $brand, $other] = $this->cabinet();
            [$conversation, $candidate] = $this->fixture($org, $brand);
            $sender = app(FollowupSender::class);
            $policy = app(FollowupPolicy::class);
            $tenant = new TenantContext($org->id, $brand->id, source: 'job');
            $failure = new class implements ChannelDelivery
            {
                public function send(DeliveryCommand $command): DeliveryReceipt
                {
                    return new DeliveryReceipt('failed', $command->messageId);
                }
            };
            self::assertFalse($sender->send($tenant, $candidate, $failure, $policy));
            self::assertFalse($sender->send(new TenantContext($org->id, $other->id, source: 'job'), $candidate, app(FakeChannelDelivery::class), $policy));
            DB::table('conversations')->where('id', $conversation)->update(['last_client_message_at' => now()]);
            self::assertFalse($sender->send($tenant, $candidate, app(FakeChannelDelivery::class), $policy));
            $this->assertDatabaseHas('seller_followup_candidates', ['id' => $candidate, 'status' => 'pending']);
            $this->assertDatabaseHas('conversations', ['id' => $conversation, 'followups_sent' => 0]);
            self::assertSame(1, DB::table('messages')->where('conversation_id', $conversation)->count());
            Http::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_bulk_delivery_respects_schedule_and_revocation(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        try {
            [, $org, $brand] = $this->cabinet();
            [$conversation, $candidate] = $this->fixture($org, $brand);
            $tenant = new TenantContext($org->id, $brand->id, source: 'job');
            $sender = app(FollowupSender::class);
            $consent = app(FollowupConsent::class);
            $send = fn () => $sender->send($tenant, $candidate, app(FakeChannelDelivery::class), app(FollowupPolicy::class));
            DB::table('seller_followup_candidates')->where('id', $candidate)->update(['bulk' => true, 'scheduled_at' => now()->addHour()]);
            self::assertFalse($send());
            $consent->record($tenant, $conversation, 'granted', 'manager', 'Explicit consent');
            self::assertFalse($send());
            DB::table('seller_followup_candidates')->where('id', $candidate)->update(['scheduled_at' => now()->subMinute()]);
            $consent->record($tenant, $conversation, 'revoked', 'manager', 'Customer opted out');
            self::assertFalse($send());
            DB::table('seller_followup_candidates')->where('id', $candidate)->update(['bulk' => false]);
            self::assertFalse($send());
            self::assertSame(1, DB::table('messages')->where('conversation_id', $conversation)->count());
            DB::table('seller_followup_candidates')->where('id', $candidate)->update(['bulk' => true]);
            $consent->record($tenant, $conversation, 'granted', 'manager', 'New consent');
            self::assertTrue($send());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_opt_out_applies_to_all_conversations_of_the_contact(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        try {
            [, $org, $brand] = $this->cabinet();
            [$conversation, $candidate] = $this->fixture($org, $brand);
            $contact = (string) Str::ulid();
            $otherConversation = (string) Str::ulid();
            DB::table('contacts')->insert(['id' => $contact, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversations')->where('id', $conversation)->update(['contact_id' => $contact]);
            DB::table('conversations')->insert(['id' => $otherConversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'contact_id' => $contact, 'created_at' => now(), 'updated_at' => now()]);
            $tenant = new TenantContext($org->id, $brand->id, source: 'job');
            $consent = app(FollowupConsent::class);
            $consent->record($tenant, $conversation, 'granted', 'manager', 'Consent');
            $consent->record($tenant, $otherConversation, 'revoked', 'manager', 'Opt out');
            $send = fn () => app(FollowupSender::class)->send($tenant, $candidate, app(FakeChannelDelivery::class), app(FollowupPolicy::class));
            self::assertTrue($consent->revoked($tenant, $conversation));
            self::assertFalse($send());
            DB::table('seller_followup_candidates')->where('id', $candidate)->update(['bulk' => true]);
            self::assertFalse($send());
            self::assertSame(1, DB::table('messages')->where('conversation_id', $conversation)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_scheduled_processing_dispatches_pending_candidate(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        Queue::fake();
        try {
            [, $org, $brand] = $this->cabinet();
            [, $candidate] = $this->fixture($org, $brand);
            (new ProcessFollowups($org->id, $brand->id))->handle(app(FollowupPolicy::class));
            Queue::assertPushed(SendFollowup::class, fn ($job) => $job->candidateId === $candidate);
        } finally {
            Carbon::setTestNow();
        }
    }
}
