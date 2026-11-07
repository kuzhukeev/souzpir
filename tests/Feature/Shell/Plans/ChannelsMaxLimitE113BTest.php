<?php

namespace Tests\Feature\Shell\Plans;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ChannelsMaxLimitE113BTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function limitChannels(string $org, string $value): void
    {
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'synthetic-'.$plan, 'name' => 'Synthetic', 'is_public' => false]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'plan_id' => $plan, 'starts_at' => now()->subDay()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'seller.channels_max', 'value' => $value, 'unit' => 'шт.']);
    }

    public function test_telegram_channel_activation_is_blocked_once_the_plan_channel_limit_is_exhausted(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->limitChannels($org->id, '0');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->put('/seller/channels/telegram', ['name' => 'Bot', 'token' => '123456:abcDEF-token_value', 'mode' => 'bot'])
            ->assertSessionHasErrors('seller.channels_max');
        self::assertSame(0, DB::table('channel_accounts')->where('organization_id', $org->id)->where('status', 'active')->count());
    }

    public function test_widget_channel_activation_is_blocked_once_the_plan_channel_limit_is_exhausted(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->limitChannels($org->id, '0');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => ['https://example.test'],
            'appearance' => ['title' => 'Связаться', 'color' => '#176b42', 'position' => 'left'],
            'behavior' => ['delay_seconds' => null, 'scroll_percent' => null, 'invitation' => 'Привет']])
            ->assertSessionHasErrors('seller.channels_max');
        self::assertSame(0, DB::table('channel_accounts')->where('organization_id', $org->id)->where('status', 'active')->count());
    }
}
