<?php

namespace Tests\Feature\Seller\Analytics;

use App\Seller\Evaluation\ScenarioFallbackJudge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BaselineAnalyticsE702KTest extends TenancyTestCase
{
    public function test_channel_rating_and_contact_conversion_are_scoped_to_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $account = (string) Str::ulid();
            $dialog = (string) Str::ulid();
            DB::table('channel_accounts')->insert(['id' => $account, 'organization_id' => $org->id, 'brand_id' => $item->id, 'type' => 'telegram', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $item->id, 'channel_account_id' => $account, 'stage' => 'need', 'state' => json_encode(['slots' => ['email' => 'client@example.test']]), 'created_at' => '2026-11-02 12:00:00+00', 'updated_at' => now()]);
            DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'conversation_id' => $dialog, 'scores' => '{}', 'issues' => '[]', 'average' => $item->id === $brand->id ? 4.2 : 1.0, 'comment' => ScenarioFallbackJudge::COMMENT, 'created_at' => now(), 'updated_at' => now()]);
        }

        $persona = (string) Str::ulid();
        $run = (string) Str::ulid();
        DB::table('seller_personas')->insert(['id' => $persona, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => $persona, 'name' => 'Тест', 'type' => 'newbie', 'payload' => '{}', 'max_turns' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_sim_runs')->insert(['id' => $run, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'persona_id' => $persona, 'status' => 'completed', 'turn_count' => 1, 'turns' => '[]', 'metrics' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'sim_run_id' => $run, 'scores' => '{}', 'issues' => '[]', 'average' => 1, 'comment' => ScenarioFallbackJudge::COMMENT, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/analytics?from=2026-11-02&to=2026-11-02')->assertOk();
        $metrics = collect($response->inertiaProps('analytics.channelMetrics'));
        $telegram = $metrics->firstWhere('channel', 'telegram');
        $this->assertSame(1, $telegram['dialogs']);
        $this->assertSame(1, $telegram['contacts']);
        $this->assertSame(100, (int) $telegram['conversion']);
        $this->assertSame(4.2, (float) $telegram['average_rating']);
        $this->assertTrue($metrics->firstWhere('channel', 'whatsapp')['planned']);
        $this->assertSame([], $response->inertiaProps('analytics.sitePages'));

        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/analytics?from=2026-11-02&to=2026-11-02')->assertOk();
        $this->get('/seller/analytics?from=bad')->assertSessionHasErrors('from');
        $this->post('/logout')->assertRedirect();
        $this->get('/seller/analytics')->assertRedirect();
    }
}
