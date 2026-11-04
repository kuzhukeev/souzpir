<?php

namespace Tests\Feature\Vision\Publications;

use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PublicationReviewE818HTest extends TenancyTestCase
{
    public function test_review_candidates_and_explicit_empty_selection_are_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => json_encode([['id' => 'q1', 'text' => 'Вопрос']]), 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => '{}', 'profile_checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $source = ['questions' => [['id' => 'q1', 'text' => 'Вопрос']], 'metrics' => ['questions' => ['q1' => ['planned' => 2, 'mention_denominator' => 2, 'mentioned' => 0]], 'slots' => []], 'recommendations' => ['items' => []]];
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->with($report)->andReturn($source);
        app()->instance(VisionReportReader::class, $reader);
        $this->get('/vision/publications?review_report='.$report)->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $response = $this->get('/vision/publications?review_report='.$report)->assertOk();
        $revision = $response->inertiaProps('reportBrandRevision');
        $response->assertInertia(fn ($page) => $page->where('review.report_id', $report)->where('review.candidates.0.key', 'action:0'));
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->post('/vision/publications', ['report_id' => $report, 'ttl_days' => 7, 'confirmed_actions' => []])->assertSessionHasNoErrors();
        $public = json_decode(DB::table('report_publications')->where('brand_id', $brand->id)->value('public_payload'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame([], $public['actions']);
        $this->withHeaders($headers)->post('/vision/publications', ['report_id' => $report, 'ttl_days' => 7, 'confirmed_actions' => ['action:0']])->assertSessionHasNoErrors();
        $payloads = DB::table('report_publications')->where('brand_id', $brand->id)->pluck('public_payload')->map(fn ($json) => json_decode($json, true, 32, JSON_THROW_ON_ERROR));
        self::assertCount(2, $payloads);
        self::assertTrue($payloads->contains(fn ($item) => ($item['actions'] ?? []) === ['Изучить ответы на этот вопрос']));
        self::assertTrue($payloads->contains(fn ($item) => ($item['actions'] ?? null) === []));
        $this->withHeaders($headers)->post('/vision/publications', ['report_id' => $report, 'ttl_days' => 7, 'confirmed_actions' => ['action:99']])->assertSessionHasErrors('confirmed_actions');
        $this->get('/vision/publications?review_report=bad')->assertSessionHasErrors('review_report');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/vision/publications?review_report='.$report)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/publications?review_report='.$report)->assertNotFound();
    }
}
