<?php

namespace Tests\Feature\Vision\Site;

use App\Vision\Core\VisionReportReader;
use App\Vision\Site\History;
use App\Vision\Site\SiteAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RecommendationCreateE822ETest extends TenancyTestCase
{
    public function test_link_is_bound_to_confirmed_report_and_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => '[{"id":"q1","text":"Вопрос"}]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(History::class, new History(app(VisionReportReader::class), app(SiteAccess::class)));
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->with($report)->andReturn(['recommendations' => ['items' => [['title' => 'Исходное название', 'priority' => 'Высокий', 'steps' => ['Шаг'], 'question_ids' => ['q1']]]]]);
        $this->app->instance(VisionReportReader::class, $reader);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = ['title' => 'Новое действие', 'report_id' => $report, 'recommendation_index' => 0];
        $this->post('/vision/actions', $input)->assertRedirect()->assertSessionHasNoErrors();
        $id = DB::table('actions')->where('brand_id', $brand->id)->where('title', 'Новое действие')->value('id');
        self::assertNotNull($id);
        $this->getJson('/vision/actions/effect')->assertOk()->assertJsonPath('recommendations.'.$id.'.priority', 'Высокий')->assertJsonPath('recommendations.'.$id.'.question_ids.0', 'q1')->assertJsonPath('recommendations.'.$id.'.steps.0', 'Шаг');
        $this->post('/vision/actions', [...$input, 'recommendation_index' => 9])->assertSessionHasErrors('recommendation');
        self::assertSame(1, DB::table('actions')->where('brand_id', $brand->id)->count());
        $this->post('/vision/actions', ['title' => 'Неполные данные', 'report_id' => $report])->assertSessionHasErrors('recommendation_index');
        $this->patch('/vision/actions/'.$id, ['title' => 'Переименовано'])->assertRedirect();
        self::assertDatabaseHas('vision_action_recommendation_links', ['action_id' => $id, 'report_id' => $report, 'recommendation_index' => 0]);
        $this->patch('/vision/actions/'.$id, ['status' => 'awaiting_measurement', 'completed_at' => now()->toDateString(), 'comment' => 'Готово'])->assertRedirect()->assertSessionHasNoErrors();
        self::assertDatabaseHas('vision_action_recommendation_links', ['action_id' => $id, 'report_id' => $report, 'recommendation_index' => 0]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/actions', $input)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/vision/actions', $input)->assertNotFound();
        self::assertSame(0, DB::table('actions')->where('brand_id', $other->id)->count());
    }
}
