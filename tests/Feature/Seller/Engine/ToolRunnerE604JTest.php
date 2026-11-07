<?php

namespace Tests\Feature\Seller\Engine;

use App\Seller\Engine\ToolRunner;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class ToolRunnerE604JTest extends LimitsTestCase
{
    public function test_price_source_is_brand_scoped_and_unrecognised_sources_are_unavailable(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $settings = ['followup' => '{}', 'off_topic' => '{}', 'negative' => '{}', 'tools' => '{}', 'greetings' => '{}', 'phrases' => '{}', 'schedule' => '{}', 'assignment' => '{}'];
        DB::table('brand_seller_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, ...$settings, 'catalog_prices_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog_settings')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'price_file_path' => 'fixture/price.csv', 'price_file_name' => 'price.csv', 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $runner = app(ToolRunner::class);
        self::assertSame(['status' => 'fake_sent', 'file' => 'price.csv'], $runner->run('send_price', ['email' => 'client@example.test'], $tenant));
        self::assertSame(['status' => 'unavailable'], $runner->run('send_price', ['email' => 'client@example.test'], new TenantContext($org->id, $other->id, source: 'job')));
        DB::table('seller_tool_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'payload' => json_encode(['price_source' => 'unknown']), 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame(['status' => 'unavailable'], $runner->run('send_price', ['email' => 'client@example.test'], $tenant));
        self::assertSame(['status' => 'invalid_arguments'], $runner->run('send_price', ['email' => 'invalid'], $tenant));
    }
}
