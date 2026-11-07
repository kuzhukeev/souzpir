<?php

namespace Tests\Feature\Seller\Followups;

use App\Seller\Channels\Core\InboundRecorder;
use App\Seller\Followups\FollowupConsent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FollowupConsentE603AATest extends TenancyTestCase
{
    public function test_mass_schedule_requires_consent_and_is_brand_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $ids = [];
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $conversation = (string) Str::ulid();
            $candidate = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_followup_candidates')->insert(['id' => $candidate, 'organization_id' => $org->id, 'brand_id' => $item->id,
                'conversation_id' => $conversation, 'reason' => 'silence', 'source_at' => now()->subDay(), 'sequence' => 1,
                'text' => 'Напоминание', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = [$conversation, $candidate];
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $payload = ['candidates' => [$ids[0][1]], 'scheduled_at' => now()->addHour()->toIso8601String()];
        $this->post('/seller/followups/schedule', $payload)->assertStatus(422);
        $this->post('/seller/followups/consent/'.$ids[1][0], ['event' => 'granted', 'basis' => 'Заявка'])->assertNotFound();
        $this->post('/seller/followups/consent/'.$ids[0][0], ['event' => 'granted', 'basis' => 'Заявка'])->assertRedirect();
        $this->post('/seller/followups/schedule', $payload)->assertRedirect();
        $this->assertDatabaseHas('seller_followup_candidates', ['id' => $ids[0][1], 'bulk' => true]);
        $this->post('/seller/followups/consent/'.$ids[0][0], ['event' => 'revoked', 'basis' => 'Отказ'])->assertRedirect();
        $this->get('/seller/followups')->assertOk()->assertInertia(fn ($page) => $page->where('counts.unsubscribed', 1)->where('items.0.consent', false));
        $this->post('/seller/followups/schedule', $payload)->assertStatus(422);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/followups/consent/'.$ids[0][0], ['event' => 'granted', 'basis' => 'test'])->assertForbidden();
    }

    public function test_channel_inbound_stop_is_recorded_once_with_message_source(): void
    {
        [, $org, $brand] = $this->cabinet();
        $channel = (string) Str::ulid();
        $conversation = (string) Str::ulid();
        DB::table('channel_accounts')->insert(['id' => $channel, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'type' => 'telegram', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'channel_account_id' => $channel, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $recorder = app(InboundRecorder::class);
        $message = $recorder->ingest($tenant, $channel, 'event-1', [], $conversation, 'Стоп');
        $recorder->ingest($tenant, $channel, 'event-1', [], $conversation, 'Стоп');
        $this->assertDatabaseHas('seller_followup_consents', ['conversation_id' => $conversation, 'message_id' => $message->id,
            'source' => 'dialog', 'event' => 'revoked']);
        self::assertSame(1, DB::table('seller_followup_consents')->where('conversation_id', $conversation)->count());
    }

    public function test_inbound_yes_requires_a_question_and_stop_revokes(): void
    {
        [, $org, $brand] = $this->cabinet();
        $conversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $consent = app(FollowupConsent::class);
        $consent->inbound($tenant, $conversation, (string) Str::ulid(), 'Да');
        self::assertFalse($consent->allowed($tenant, $conversation));
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'conversation_id' => $conversation, 'role' => 'agent', 'text' => 'Можно присылать напоминания?', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $consent->inbound($tenant, $conversation, (string) Str::ulid(), 'Да!');
        self::assertTrue($consent->allowed($tenant, $conversation));
        $consent->inbound($tenant, $conversation, (string) Str::ulid(), 'Не пишите мне');
        self::assertTrue($consent->revoked($tenant, $conversation));
        self::assertFalse($consent->allowed($tenant, $conversation));
    }
}
