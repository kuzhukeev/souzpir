<?php

namespace Tests\Feature\Vision\Reports;

use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PublicationReviewHttpE818GTest extends TenancyTestCase
{
    public function test_publication_accepts_only_reviewed_question_ids_for_current_brand(): void
    {
        [$owner, $organization, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'version' => 1, 'questions' => '[{"id":"q1","text":"Вопрос"}]', 'size' => 1,
            'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now()]);
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->with($report)->times(3)->andReturn([
            'questions' => [['id' => 'q1', 'text' => 'Вопрос']],
            'metrics' => ['questions' => ['q1' => ['planned' => 1, 'mention_denominator' => 1, 'mentioned' => 0]], 'slots' => []],
            'recommendations' => ['items' => []],
        ]);
        $this->app->instance(VisionReportReader::class, $reader);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/publications')->assertOk()->inertiaProps('reportBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $input = ['report_id' => $report, 'ttl_days' => 7];
        $this->withHeaders($headers)->post('/vision/publications', [...$input, 'confirmed_actions' => ['action:0']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $first = DB::table('report_publications')->where('report_id', $report)->first();
        self::assertSame(['Изучить ответы на этот вопрос'], json_decode($first->public_payload, true)['actions']);
        self::assertSame(['q1'], json_decode($first->public_payload, true)['action_question_ids']);
        $this->withHeaders($headers)->post('/vision/publications', [...$input, 'confirmed_actions' => ['action:9']])
            ->assertSessionHasErrors('confirmed_actions');
        $this->withHeaders($headers)->post('/vision/publications', [...$input, 'confirmed_actions' => []])
            ->assertRedirect()->assertSessionHasNoErrors();
        $last = DB::table('report_publications')->where('report_id', $report)->orderByDesc('created_at')->orderByDesc('id')->first();
        self::assertSame([], json_decode($last->public_payload, true)['actions']);
        self::assertSame('{}', DB::table('vision_reports')->where('id', $report)->value('payload'));

        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->post('/vision/publications', [...$input, 'confirmed_actions' => ['action:0']])->assertNotFound();
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])
            ->post('/vision/publications', [...$input, 'confirmed_actions' => ['action:0']])->assertForbidden();
    }
}
