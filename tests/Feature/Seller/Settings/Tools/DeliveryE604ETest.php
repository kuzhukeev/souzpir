<?php

namespace Tests\Feature\Seller\Settings\Tools;

use App\Seller\Runtime\ConfiguredToolHandler;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DeliveryE604ETest extends TenancyTestCase
{
    public function test_delivery_uses_brand_settings_and_respects_disabled_tool(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/scenario/tools', ['tools' => ['calc_delivery' => true], 'requisites' => '', 'delivery_rules' => [['city' => 'Moscow', 'price_min' => 100, 'price_max' => 200, 'days_min' => 1, 'days_max' => 2]]])->assertSessionHasNoErrors();
        $handler = app(ConfiguredToolHandler::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        self::assertSame('ready', $handler->calculateDelivery($tenant, ['city' => 'Moscow'])['status']);
        self::assertSame('invalid_arguments', $handler->calculateDelivery($tenant, ['city' => 123])['status']);
        self::assertSame('not_configured', $handler->calculateDelivery(new TenantContext($org->id, $other->id, source: 'job'), ['city' => 'Moscow'])['status']);
        $this->post('/seller/scenario/tools', ['tools' => ['calc_delivery' => false], 'requisites' => '', 'delivery_rules' => []])->assertSessionHasNoErrors();
        self::assertSame('unavailable', $handler->calculateDelivery($tenant, ['city' => 'Moscow'])['status']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/scenario/tools', ['tools' => ['calc_delivery' => true]])->assertForbidden();
        Http::assertNothingSent();
    }
}
