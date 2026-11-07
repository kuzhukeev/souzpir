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

    /** channel_overrides is read generically from the settings JSON payload by BrandRuntimeSchedule;
     * writing it through the settings UI/route is a separate task (app/Seller/Settings/Schedule/ScheduleController.php is out of this card's scope). */
    public function test_channel_override_wins_over_module_mode_when_present_in_settings(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        DB::table('seller_schedule_preferences')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'payload' => json_encode(['mode' => 'off', 'week' => [], 'holidays' => [], 'channel_overrides' => ['widget' => 'autonomous', 'ignored' => 'bogus']], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now()]);

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
