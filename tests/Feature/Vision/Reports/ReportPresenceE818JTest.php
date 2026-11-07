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

final class ReportPresenceE818JTest extends TenancyTestCase
{
    public function test_report_uses_confirmed_presence_and_scoped_questionnaire(): void
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
        foreach ([[$brand, 'present.test', 'present', now()->subDay()], [$brand, 'absent.test', 'absent', now()->subDay()], [$brand, 'future.test', 'present', now()->addDay()], [$other, 'foreign.test', 'present', now()->subDay()]] as [$item, $domain, $presence, $date]) {
            DB::table('vision_source_presence')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'domain' => $domain, 'presence' => $presence, 'created_at' => $date, 'updated_at' => $date]);
        }
        DB::table('visibility_quizzes')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $owner->id, 'current_step' => 8, 'answers' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->once()->with($report)->andReturn(['measurement_id' => $measurement, 'metrics' => ['sources' => [['domain' => 'present.test', 'answers' => 1], ['domain' => 'absent.test', 'answers' => 1], ['domain' => 'future.test', 'answers' => 1], ['domain' => 'foreign.test', 'answers' => 1]]], 'questions' => []]);
        app()->instance(VisionReportReader::class, $reader);
        $result = app(ReportData::class)->read(new TenantContext($org->id, $brand->id), $report);
        self::assertTrue($result['questionnaire_complete']);
        self::assertSame([true, false, null, null], array_column($result['report']['sections']['sources'], 'presence'));
        $otherData = app(ReportData::class)->read(new TenantContext($org->id, $other->id), null);
        self::assertFalse($otherData['questionnaire_complete']);
        self::assertNull($otherData['report']);
    }

    public function test_report_data_requires_authentication(): void
    {
        $this->getJson('/vision/report/data')->assertUnauthorized();
    }
}
