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

/** SP-E6-01E: saved "напоминание"/"прощание" phrases override the follow-up nudge text. */
final class ReminderGoodbyePhraseE601ETest extends TenancyTestCase
{
    private function fixture($org, $brand, array $servicePhrases, int $maxAuto = 2, int $followupsSent = 0): string
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brand_seller_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'followup' => json_encode(['first_hours' => 20, 'second_hours' => 20, 'max_auto' => $maxAuto, 'window' => null, 'texts' => ['Шаблонное напоминание']]),
            'off_topic' => '{}', 'negative' => '{}', 'tools' => '{}', 'greetings' => '{}', 'phrases' => '{}', 'schedule' => '{}', 'assignment' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['persona'] = ['name' => 'Мария', 'service_phrases' => $servicePhrases];
        $snapshot = new KnowledgeSnapshot($data, 'release', 'checksum');
        $this->mock(KnowledgeRepository::class)->shouldReceive('published')->once()->withArgs(fn ($tenant) => $tenant->organizationId === $org->id && $tenant->brandId === $brand->id)->andReturn($snapshot);
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'stage' => 'need', 'state' => '{}',
            'followups_sent' => $followupsSent, 'last_client_message_at' => now()->subHours(23), 'created_at' => now()->subHours(23), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $id,
            'role' => 'agent', 'text' => 'Ответ', 'meta' => '{}', 'created_at' => now()->subHours(21), 'updated_at' => now()->subHours(21)]);

        return $id;
    }

    public function test_reminder_phrase_overrides_the_template_before_the_last_attempt(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        Http::preventStrayRequests();
        try {
            [, $org, $brand] = $this->cabinet();
            $conversation = $this->fixture($org, $brand, ['reminder' => 'Кастомное напоминание о заявке.'], maxAuto: 2, followupsSent: 0);
            (new PrepareFollowups($org->id, $brand->id))->handle(app(FollowupPolicy::class));
            $this->assertDatabaseHas('seller_followup_candidates', ['conversation_id' => $conversation, 'sequence' => 1, 'text' => 'Кастомное напоминание о заявке.']);
            Http::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_goodbye_phrase_overrides_the_template_on_the_last_allowed_attempt(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        Http::preventStrayRequests();
        try {
            [, $org, $brand] = $this->cabinet();
            $conversation = $this->fixture($org, $brand, ['reminder' => 'Кастомное напоминание', 'goodbye' => 'Спасибо, завершаем переписку.'], maxAuto: 2, followupsSent: 1);
            (new PrepareFollowups($org->id, $brand->id))->handle(app(FollowupPolicy::class));
            $this->assertDatabaseHas('seller_followup_candidates', ['conversation_id' => $conversation, 'sequence' => 2, 'text' => 'Спасибо, завершаем переписку.']);
            Http::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_without_a_saved_phrase_the_template_text_is_used(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        Http::preventStrayRequests();
        try {
            [, $org, $brand] = $this->cabinet();
            $conversation = $this->fixture($org, $brand, [], maxAuto: 2, followupsSent: 1);
            (new PrepareFollowups($org->id, $brand->id))->handle(app(FollowupPolicy::class));
            $this->assertDatabaseHas('seller_followup_candidates', ['conversation_id' => $conversation, 'sequence' => 2, 'text' => 'Шаблонное напоминание']);
            Http::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }
}
