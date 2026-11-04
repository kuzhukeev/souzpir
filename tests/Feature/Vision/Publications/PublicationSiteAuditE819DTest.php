<?php

namespace Tests\Feature\Vision\Publications;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Core\VisionReportReader;
use App\Vision\Reports\ReportData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PublicationSiteAuditE819DTest extends TenancyTestCase
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
            DB::table('audit_runs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'created_by' => $owner->id, 'result' => json_encode(['url' => $url, 'readiness' => ['lower' => $score, 'upper' => $score + 5], 'criteria' => [['id' => 3, 'status' => 'pass', 'evidence' => [['url' => 'https://private.test']]]]]), 'created_at' => $date, 'updated_at' => $date]);
        }
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->times(2)->with($report)->andReturn(['measurement_id' => $measurement, 'metrics' => [], 'questions' => []]);
        app()->instance(VisionReportReader::class, $reader);
        $result = app(ReportData::class)->read(new TenantContext($org->id, $brand->id), $report);
        self::assertSame(20, $result['report']['sections']['site_audit']['readiness']['lower']);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/publications')->assertOk()->inertiaProps('reportBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->post('/vision/publications', ['report_id' => $report, 'ttl_days' => 7])->assertRedirect();
        $this->get(parse_url(session('publication_url'), PHP_URL_PATH))->assertOk()->assertSee('20–25')->assertSee('Правила доступа роботов')->assertDontSee('private.test')->assertDontSee('90–95')->assertDontSee('99–104');
        $invalid = (object) ['profile_snapshot' => json_encode($profile), 'profile_checksum' => str_repeat('f', 64)];
        self::assertNull(app(ReportData::class)->siteFromProfile($invalid));
        self::assertNull(app(ReportData::class)->siteAudit(new TenantContext($org->id, $brand->id), null, now()->toDateTimeString()));
    }
}
