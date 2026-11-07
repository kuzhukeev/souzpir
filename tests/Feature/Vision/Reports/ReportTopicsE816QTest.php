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

final class ReportTopicsE816QTest extends TenancyTestCase
{
    private function measurement(string $org, string $brand, string $owner, string $date, bool $confirmed = true): array
    {
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org, 'brand_id' => $brand, 'version' => DB::table('question_sets')->where('brand_id', $brand)->count() + 1, 'questions' => '[{}]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => $date, 'updated_at' => $date]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org, 'brand_id' => $brand, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => '{}', 'profile_checksum' => CanonicalJson::hash([]), 'created_at' => $date, 'updated_at' => $date]);
        if ($confirmed) {
            DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'measurement_id' => $measurement, 'confirmed_by' => $owner, 'created_at' => $date, 'updated_at' => $date]);
        }

        return [$measurement, $this->report($org, $brand, $measurement, 1, $date)];
    }

    private function report(string $org, string $brand, string $measurement, int $revision, string $date): string
    {
        $id = (string) Str::ulid();
        DB::table('vision_reports')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'measurement_id' => $measurement, 'revision' => $revision, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => $date, 'updated_at' => $date]);

        return $id;
    }

    private function payload(string $measurement, int $visibility, int $runs = 1): array
    {
        return ['measurement_id' => $measurement, 'questions' => [], 'question_set_checksum' => str_repeat('a', 64),
            'providers' => ['fake' => 'model'], 'runs' => $runs,
            'metrics' => ['visibility' => ['macro_percent' => $visibility], 'panel' => [], 'intents' => ['price' => ['mention_rate' => 75, 'mention_denominator' => 4], 'buy' => ['mention_rate' => null, 'mention_denominator' => 0], 'choose' => ['mention_rate' => 25, 'mention_denominator' => 4]]]];
    }

    public function test_history_uses_latest_revision_per_older_measurement_and_excludes_incompatible_and_foreign(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$first, $firstReport] = $this->measurement($org->id, $brand->id, $owner->id, '2026-11-01 10:00:00+00');
        [$second, $secondReport] = $this->measurement($org->id, $brand->id, $owner->id, '2026-11-02 10:00:00+00');
        [$incompatible, $incompatibleReport] = $this->measurement($org->id, $brand->id, $owner->id, '2026-11-03 10:00:00+00');
        [$third, $thirdReport] = $this->measurement($org->id, $brand->id, $owner->id, '2026-11-04 10:00:00+00');
        [$foreign] = $this->measurement($org->id, $other->id, $owner->id, '2026-11-05 10:00:00+00');
        $this->measurement($org->id, $brand->id, $owner->id, '2026-11-06 10:00:00+00', false);
        $firstRevision2 = $this->report($org->id, $brand->id, $first, 2, '2026-11-07 10:00:00+00');
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->with($firstRevision2)->andReturn($this->payload($first, 15));
        $reader->shouldReceive('read')->with($secondReport)->andReturn($this->payload($second, 30));
        $reader->shouldReceive('read')->with($incompatibleReport)->andReturn($this->payload($incompatible, 99, 2));
        $reader->shouldReceive('read')->with($thirdReport)->andReturn($this->payload($third, 45));
        app()->instance(VisionReportReader::class, $reader);
        $data = app(ReportData::class)->read(new TenantContext($org->id, $brand->id), null);
        self::assertSame($thirdReport, $data['report']['id']);
        self::assertSame([['name' => 'price', 'visibility' => 75.0, 'delta' => 0.0], ['name' => 'choose', 'visibility' => 25.0, 'delta' => 0.0]], $data['report']['topics']);
        self::assertSame([$first, $second, $third], array_column($data['report']['visibility_history'], 'measurement_id'));
        self::assertSame([15.0, 30.0, 45.0], array_column($data['report']['visibility_history'], 'visibility'));
        self::assertStringStartsWith('2026-11-01', $data['report']['visibility_history'][0]['date']);
        self::assertSame($thirdReport, $data['reports'][0]['id']);
        self::assertNotContains($foreign, array_column($data['report']['visibility_history'], 'measurement_id'));
        $selectedOld = app(ReportData::class)->read(new TenantContext($org->id, $brand->id), $secondReport);
        self::assertSame([$first, $second], array_column($selectedOld['report']['visibility_history'], 'measurement_id'));
        self::assertNotSame($firstReport, $data['reports'][0]['id']);
    }
}
