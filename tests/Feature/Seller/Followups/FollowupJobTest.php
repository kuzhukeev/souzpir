<?php

namespace Tests\Feature\Seller\Followups;

use App\Seller\Followups\FollowupPolicy;
use App\Seller\Followups\PrepareFollowups;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FollowupJobTest extends TenancyTestCase
{
    private function conversation(string $org, string $brand): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'stage' => 'need',
            'state' => '{}', 'last_client_message_at' => now()->subHours(23), 'created_at' => now()->subHours(23), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand,
            'conversation_id' => $id, 'role' => 'agent', 'text' => 'Ответ', 'meta' => '{}', 'created_at' => now()->subHours(21), 'updated_at' => now()->subHours(21)]);

        return $id;
    }

    public function test_prepares_only_the_enabled_brand_without_sending(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00+00');
        Http::preventStrayRequests();
        Queue::fake();
        try {
            [, $org, $brand, $other] = $this->cabinet();
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('brand_seller_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'followup' => json_encode(['first_hours' => 20, 'second_hours' => null, 'max_auto' => 2, 'window' => null, 'texts' => ['Здравствуйте!']]),
                'off_topic' => '{}', 'negative' => '{}', 'tools' => '{}', 'greetings' => '{}', 'phrases' => '{}', 'schedule' => '{}', 'assignment' => '{}',
                'created_at' => now(), 'updated_at' => now()]);
            $one = $this->conversation($org->id, $brand->id);
            $two = $this->conversation($org->id, $other->id);
            (new PrepareFollowups($org->id, $other->id))->handle(app(FollowupPolicy::class));
            $this->assertDatabaseCount('seller_followup_candidates', 0);
            (new PrepareFollowups($org->id, $brand->id))->handle(app(FollowupPolicy::class));
            (new PrepareFollowups($org->id, $brand->id))->handle(app(FollowupPolicy::class));
            $this->assertDatabaseCount('seller_followup_candidates', 1);
            $this->assertDatabaseHas('seller_followup_candidates', ['conversation_id' => $one, 'brand_id' => $brand->id, 'status' => 'pending', 'reason' => 'silence', 'text' => 'Здравствуйте!']);
            self::assertTrue(Carbon::parse(DB::table('seller_followup_candidates')->where('conversation_id', $one)->value('scheduled_at'))->equalTo(now()));
            $this->assertDatabaseMissing('seller_followup_candidates', ['conversation_id' => $two]);
            $this->assertDatabaseCount('messages', 2);
            Http::assertNothingSent();
            Queue::assertNothingPushed();
        } finally {
            Carbon::setTestNow();
        }
    }
}
