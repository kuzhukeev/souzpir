<?php

namespace Tests\Feature\Vision\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class LaunchEstimateE815HTest extends TenancyTestCase
{
    public function test_latest_completed_duration_is_brand_scoped_and_absent_when_no_measurement_exists(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $set = (string) Str::ulid();
            DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $item->id, 'version' => 1, 'questions' => '["Вопрос"]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
            if ($item->id === $other->id) {
                DB::table('measurements')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'started_at' => '2026-11-01 10:00:00', 'finished_at' => '2026-11-01 10:50:00', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $brandSet = $set;
            }
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()->assertJsonPath('last_duration_minutes', null);
        DB::table('measurements')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $brandSet, 'status' => 'completed', 'configuration' => '{}', 'started_at' => '2026-11-01 10:00:00', 'finished_at' => '2026-11-01 10:12:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()->assertJsonPath('last_duration_minutes', 12);
        $this->withHeaders(['X-Brand-Id' => $other->id, 'X-Brand-Revision' => $revision])->getJson('/vision/runs/data?view=details')->assertNotFound();
        Http::assertNothingSent();
    }
}
