<?php

namespace Tests\Feature\Vision\Measurements;

use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
use App\Vision\Measurements\MeasurementList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class VisibilityChangeE816KTest extends TenancyTestCase
{
    public function test_comparable_confirmed_reports_show_before_for_only_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $set = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => '["Question"]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        $reports = [];
        foreach ([30, 42] as $index => $value) {
            $date = now()->subDays(2 - $index);
            $measurement = (string) Str::ulid();
            $report = (string) Str::ulid();
            DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => $date, 'updated_at' => $date]);
            DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => $date, 'updated_at' => $date]);
            DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => $date, 'updated_at' => $date]);
            $reports[$report] = ['question_set_checksum' => 'same', 'providers' => ['fake'], 'runs' => 1, 'metrics' => ['visibility' => ['macro_percent' => $value]], 'questions' => []];
        }
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->andReturnUsing(function ($id) use (&$reports) {
            return $reports[$id];
        });
        app()->instance(VisionReportReader::class, $reader);
        $data = app(MeasurementList::class)->readDetailed(new TenantContext($org->id, $brand->id), 1);
        self::assertSame(30, $data['data'][0]['visibility_before']);
        self::assertSame(42.0, $data['data'][0]['visibility']);
        self::assertSame(12.0, $data['data'][0]['visibility_delta']);
        self::assertNull($data['data'][1]['visibility_before']);
        self::assertSame([], app(MeasurementList::class)->readDetailed(new TenantContext($org->id, $other->id), 1)['data']);
        $reports[array_key_first($reports)]['question_set_checksum'] = 'different';
        $incomparable = app(MeasurementList::class)->readDetailed(new TenantContext($org->id, $brand->id), 1);
        self::assertNull($incomparable['data'][0]['visibility_before']);
        self::assertNull($incomparable['data'][0]['visibility_delta']);
        $this->getJson('/vision/runs/data')->assertUnauthorized();
    }
}
