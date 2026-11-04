<?php

namespace Tests\Feature\Vision\Reports;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
use App\Vision\Reports\ReportData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReportSiteAuditE819Test extends TenancyTestCase
{
    public function test_report_uses_only_past_matching_brand_and_site_audit(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => '[{"id":"q1","text":"Вопрос","intent":"general"}]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        $profile = ['site' => 'https://example.test'];
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => json_encode($profile), 'profile_checksum' => CanonicalJson::hash($profile), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$brand, 'https://example.test', 20, now()->subDay()], [$brand, 'https://other.test', 90, now()->subMinute()], [$other, 'https://example.test', 70, now()->subMinute()], [$brand, 'https://example.test', 99, now()->addDay()]] as [$item,$url,$score,$date]) {
            DB::table('audit_runs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'created_by' => $owner->id, 'result' => json_encode(['url' => $url, 'readiness' => ['lower' => $score, 'upper' => $score + 5]]), 'created_at' => $date, 'updated_at' => $date]);
        }
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->once()->with($report)->andReturn(['measurement_id' => $measurement, 'metrics' => [], 'questions' => []]);
        app()->instance(VisionReportReader::class, $reader);
        $result = app(ReportData::class)->read(new TenantContext($org->id, $brand->id), $report);
        self::assertSame(20, $result['report']['sections']['site_audit']['readiness']['lower']);
    }
}
