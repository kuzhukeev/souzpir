<?php

namespace Tests\Feature\Seller\Analytics;

use App\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SourceFilterE704MTest extends TenancyTestCase
{
    public function test_api_source_is_shown_and_filterable_scoped_to_brand(): void
    {
        Http::preventStrayRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($org->id, $brand), 'Idempotency-Key' => 'site-lead'])
            ->postJson('/api/v1/leads', ['fields' => ['name' => 'Анна'], 'source' => 'site'])->assertCreated();
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($org->id, $brand), 'Idempotency-Key' => 'crm-lead'])
            ->postJson('/api/v1/leads', ['fields' => ['name' => 'Борис'], 'source' => 'crm'])->assertCreated();
        DB::table('channel_accounts')->insert(['id' => $account = (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'telegram', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'channel_account_id' => $account, 'stage' => 'open', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($org->id, $other), 'Idempotency-Key' => 'other-lead'])
            ->postJson('/api/v1/leads', ['fields' => ['name' => 'Чужой'], 'source' => 'site'])->assertCreated();

        $all = $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/analytics')->assertOk();
        $this->assertSame(['site' => 1, 'crm' => 1, 'unknown' => 1], $all->inertiaProps('analytics.sources'));
        $this->assertSame(3, $all->inertiaProps('analytics.total'));

        $site = $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/analytics?source=site')->assertOk();
        $this->assertSame(1, $site->inertiaProps('analytics.total'));
        $this->assertSame(['site' => 1], $site->inertiaProps('analytics.sources'));

        $this->get('/seller/analytics?source=bad')->assertSessionHasErrors('source');
    }

    private function token(string $org, Brand $brand): string
    {
        $secret = Str::random(64);
        $id = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org, 'brand_id' => $brand->id, 'name' => 'synthetic', 'token' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4), 'abilities' => json_encode(['leads']), 'created_at' => now(), 'updated_at' => now()]);

        return $id.'|'.$secret;
    }
}
