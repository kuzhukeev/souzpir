<?php

namespace Tests\Feature\Vision\Settings;

use App\Tenancy\TenantContext;
use App\Vision\Settings\ScheduleHistoryEstimate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ScheduleHistoryE815JTest extends TenancyTestCase
{
    public function test_saving_schedule_creates_a_brand_scoped_version(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/settings')->assertOk()->inertiaProps('revision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])->put('/vision/runs/schedule', ['enabled' => false, 'models' => []])->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('vision_schedule_versions')->where('brand_id', $brand->id)->count());
        self::assertSame(0, DB::table('vision_schedule_versions')->where('brand_id', $other->id)->count());
        $snapshot = json_decode(DB::table('vision_schedule_versions')->where('brand_id', $brand->id)->value('schedule'), true);
        self::assertFalse($snapshot['enabled']);
    }

    public function test_versions_count_only_their_own_dates_and_brands(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $schedule = ['enabled' => true, 'frequency' => 'weekly', 'weekday' => 1, 'local_time' => '09:00', 'timezone' => 'Europe/Moscow', 'models' => ['a'], 'next_run_at' => '2026-11-02 06:00:00+00'];
        $insert = function ($item, string $at, array $snapshot) use ($org): void {
            DB::table('vision_schedule_versions')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'effective_at' => $at, 'schedule' => json_encode($snapshot)]);
        };
        $insert($brand, '2026-10-31 00:00:00+00', $schedule);
        $changed = [...$schedule, 'frequency' => 'biweekly', 'models' => ['a', 'b'], 'next_run_at' => '2026-11-16 06:00:00+00'];
        $insert($brand, '2026-11-15 00:00:00+00', $changed);
        $insert($other, '2026-11-01 00:00:00+00', [...$schedule, 'models' => ['a', 'b', 'c']]);
        $estimator = app(ScheduleHistoryEstimate::class);
        self::assertSame(['runs' => 4, 'slots' => 18], $estimator->estimate($tenant, $changed, 3, '2026-11-01', '2026-11-30'));
        self::assertSame(['runs' => 1, 'slots' => 6], $estimator->estimate($tenant, $changed, 3, '2026-11-16', '2026-11-16'));
        self::assertNull($estimator->estimate($tenant, $changed, 3, '2026-10-01', '2026-11-30'));
        self::assertNull($estimator->estimate($tenant, $changed, 3, '2026-10-31', '2026-10-31'));
        self::assertNull($estimator->estimate($tenant, $changed, null, '2026-11-01', '2026-11-30'));
        $insert($brand, '2026-11-20 00:00:00+00', [...$changed, 'enabled' => false]);
        self::assertSame(['runs' => 3, 'slots' => 12], $estimator->estimate($tenant, $changed, 3, '2026-11-01', '2026-11-30'));
    }
}
