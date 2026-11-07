<?php

namespace Tests\Feature\Vision\Reports;

use App\Ai\CanonicalJson;
use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PublicHistoryE819FTest extends TenancyTestCase
{
    private function report(string $org, string $brand, string $owner, string $date): string
    {
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org, 'brand_id' => $brand, 'version' => DB::table('question_sets')->where('brand_id', $brand)->count() + 1, 'questions' => '[{}]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => $date, 'updated_at' => $date]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org, 'brand_id' => $brand, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => '{}', 'profile_checksum' => CanonicalJson::hash([]), 'created_at' => $date, 'updated_at' => $date]);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org, 'brand_id' => $brand, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => $date, 'updated_at' => $date]);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'measurement_id' => $measurement, 'confirmed_by' => $owner, 'created_at' => $date, 'updated_at' => $date]);

        return $report;
    }

    public function test_history_is_comparable_scoped_frozen_and_revocable(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $past = $this->report($org->id, $brand->id, $owner->id, now()->subDays(3)->toDateTimeString());
        $different = $this->report($org->id, $brand->id, $owner->id, now()->subDays(2)->toDateTimeString());
        $foreign = $this->report($org->id, $other->id, $owner->id, now()->subDay()->toDateTimeString());
        $unconfirmed = $this->report($org->id, $brand->id, $owner->id, now()->subHours(12)->toDateTimeString());
        $unconfirmedMeasurement = DB::table('vision_reports')->where('id', $unconfirmed)->value('measurement_id');
        DB::table('snapshot_confirmations')->where('measurement_id', $unconfirmedMeasurement)->delete();
        $current = $this->report($org->id, $brand->id, $owner->id, now()->toDateTimeString());
        $payload = ['questions' => [], 'question_set_checksum' => str_repeat('a', 64), 'providers' => ['fake' => 'model'], 'runs' => 1,
            'metrics' => ['visibility' => ['macro_percent' => 40, 'coverage' => 80], 'panel' => ['share_of_voice' => 20, 'position_average' => 2]],
            'internal_prompt' => 'секретный текст'];
        $old = $payload;
        $old['metrics']['visibility']['macro_percent'] = 20;
        $mismatch = $payload;
        $mismatch['runs'] = 2;
        $mismatch['metrics']['visibility']['macro_percent'] = 99;
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->once()->with($current)->andReturn($payload);
        $reader->shouldReceive('read')->once()->with($past)->andReturn($old);
        $reader->shouldReceive('read')->once()->with($different)->andReturn($mismatch);
        app()->instance(VisionReportReader::class, $reader);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/publications')->assertOk()->inertiaProps('reportBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->post('/vision/publications', ['report_id' => $foreign, 'ttl_days' => 7])->assertNotFound();
        $this->withHeaders($headers)->post('/vision/publications', ['report_id' => $current, 'ttl_days' => 999])->assertSessionHasErrors('ttl_days');
        $this->withHeaders($headers)->post('/vision/publications', ['report_id' => $current, 'ttl_days' => 7])->assertRedirect()->assertSessionHas('publication_url');
        $path = parse_url(session('publication_url'), PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('История изменений')->assertSee('Динамика: Видимость')->assertSee('20,0')->assertSee('40,0')->assertDontSee('99,0')->assertDontSee('секретный текст')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $publication = DB::table('report_publications')->where('report_id', $current)->first();
        $snapshot = json_decode($publication->public_payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $snapshot['history']);
        DB::table('report_publications')->where('id', $publication->id)->update(['expires_at' => now()->subSecond()]);
        $this->get($path)->assertStatus(410)->assertDontSee('История изменений');
        DB::table('report_publications')->where('id', $publication->id)->update(['expires_at' => now()->addDay()]);
        $this->withHeaders($headers)->delete('/vision/publications/'.$publication->id)->assertRedirect();
        $this->get($path)->assertStatus(410)->assertDontSee('История изменений')->assertDontSee('20,0');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/publications', ['report_id' => $current, 'ttl_days' => 7])->assertForbidden();
    }
}
