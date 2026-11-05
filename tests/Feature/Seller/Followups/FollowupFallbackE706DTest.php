<?php

namespace Tests\Feature\Seller\Followups;

use App\Seller\Followups\FollowupPolicy;
use App\Seller\Followups\PrepareFollowups;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FollowupFallbackE706DTest extends TenancyTestCase
{
    public function test_both_fallbacks_use_current_brand_and_published_persona(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        Http::preventStrayRequests();
        try {
            [, $org, $brand, $other] = $this->cabinet();
            DB::table('brands')->where('id', $brand->id)->update(['name' => 'Маяк']);
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('brand_seller_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'followup' => json_encode(['first_hours' => 20, 'window' => null, 'texts' => []]), 'off_topic' => '{}', 'negative' => '{}', 'tools' => '{}', 'greetings' => '{}', 'phrases' => '{}', 'schedule' => '{}', 'assignment' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['persona'] = ['name' => 'Мария'];
            $snapshot = new KnowledgeSnapshot($data, 'release', 'checksum');
            $this->mock(KnowledgeRepository::class)->shouldReceive('published')->once()->withArgs(fn ($tenant) => $tenant->organizationId === $org->id && $tenant->brandId === $brand->id)->andReturn($snapshot);
            foreach ([[], ['slots' => ['next_contact_at' => now()->subHour()->toIso8601String()]]] as $state) {
                $id = (string) Str::ulid();
                DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'stage' => 'need', 'state' => json_encode($state), 'last_client_message_at' => now()->subHours(23), 'created_at' => now()->subHours(23), 'updated_at' => now()]);
                DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $id, 'role' => 'agent', 'text' => 'Ответ', 'meta' => '{}', 'created_at' => now()->subHours(21), 'updated_at' => now()->subHours(21)]);
            }
            (new PrepareFollowups($org->id, $brand->id))->handle(app(FollowupPolicy::class));
            $candidates = DB::table('seller_followup_candidates')->where('brand_id', $brand->id)->get();
            self::assertCount(2, $candidates);
            foreach ($candidates as $candidate) {
                self::assertStringContainsString('Мария', $candidate->text);
                self::assertStringContainsString('Маяк', $candidate->text);
                self::assertStringNotContainsString('Антон', $candidate->text);
                self::assertStringNotContainsString('Союз Пир', $candidate->text);
            }
            $this->assertDatabaseMissing('seller_followup_candidates', ['brand_id' => $other->id]);
            Http::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_missing_persona_uses_neutral_brand_only_introduction(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        try {
            [, $org, $brand] = $this->cabinet();
            DB::table('brands')->where('id', $brand->id)->update(['name' => 'Маяк']);
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $snapshot = new KnowledgeSnapshot(array_fill_keys(KnowledgeSnapshot::FILES, []), 'release', 'checksum');
            $this->mock(KnowledgeRepository::class)->shouldReceive('published')->once()->andReturn($snapshot);
            $id = (string) Str::ulid();
            DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'stage' => 'need', 'state' => '{}', 'last_client_message_at' => now()->subHours(23), 'created_at' => now()->subHours(23), 'updated_at' => now()]);
            DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $id, 'role' => 'agent', 'text' => 'Ответ', 'meta' => '{}', 'created_at' => now()->subHours(21), 'updated_at' => now()->subHours(21)]);
            (new PrepareFollowups($org->id, $brand->id))->handle(app(FollowupPolicy::class));
            $text = DB::table('seller_followup_candidates')->where('conversation_id', $id)->value('text');
            self::assertIsString($text);
            self::assertStringContainsString('Маяк', $text);
            self::assertStringNotContainsString('Антон', $text);
            self::assertStringNotContainsString('Союз Пир', $text);
            self::assertStringNotContainsString('Это ', $text);
        } finally {
            Carbon::setTestNow();
        }
    }
}
