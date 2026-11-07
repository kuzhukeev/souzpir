<?php

namespace Tests\Feature\Seller\Settings\Schedule;

use App\Seller\Runtime\BrandRuntimeSchedule;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ChannelOverrideE607DTest extends TenancyTestCase
{
    private function enable(string $organizationId, string $brandId): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'brand_id' => $brandId, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_channel_override_is_saved_validated_and_wins_over_module_mode(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);

        $this->post('/seller/settings/schedule', ['mode' => 'off', 'week' => [], 'holidays' => [], 'channel_overrides' => ['widget' => 'autonomous']])->assertRedirect();
        $this->get('/seller/settings')->assertOk()->assertInertia(fn ($page) => $page->where('settings.channel_overrides.widget', 'autonomous'));

        $this->post('/seller/settings/schedule', ['mode' => 'off', 'week' => [], 'holidays' => [], 'channel_overrides' => ['pigeon' => 'autonomous']])->assertSessionHasErrors('channel_overrides');
        $this->post('/seller/settings/schedule', ['mode' => 'off', 'week' => [], 'holidays' => [], 'channel_overrides' => ['widget' => 'wrong']])->assertSessionHasErrors('channel_overrides.widget');

        $tenant = new TenantContext($org->id, $brand->id, $owner->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $schedule = app(BrandRuntimeSchedule::class);
            $settings = $schedule->settings($tenant);
            $now = CarbonImmutable::parse('2026-11-10T12:00:00Z');
            $this->assertSame('off', $schedule->mode($tenant, $settings, $now));
            $this->assertSame('autonomous', $schedule->mode($tenant, $settings, $now, 'widget'));
            $this->assertSame('off', $schedule->mode($tenant, $settings, $now, 'telegram'));
            $this->assertFalse($schedule->available($tenant, $settings, $now, 'telegram'));
            $this->assertTrue($schedule->available($tenant, $settings, $now, 'widget'));
        });
    }

    public function test_fallback_phrase_defaults_without_published_persona(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $this->assertSame('Передаю менеджеру, он напишет сегодня до конца дня.', app(BrandRuntimeSchedule::class)->fallbackPhrase($tenant));
        });
    }
}
