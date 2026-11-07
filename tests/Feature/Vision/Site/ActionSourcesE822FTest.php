<?php

namespace Tests\Feature\Vision\Site;

use App\Vision\Core\VisionReportReader;
use App\Vision\Site\History;
use App\Vision\Site\SiteAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ActionSourcesE822FTest extends TenancyTestCase
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
        $scheduled = now()->addDays(3);
        DB::table('monitoring_schedules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'enabled' => true, 'next_run_at' => $scheduled, 'created_at' => now(), 'updated_at' => now()]);
        $storedDate = DB::table('monitoring_schedules')->where('brand_id', $brand->id)->value('next_run_at');
        $this->getJson('/vision/actions/effect')->assertOk()->assertJsonPath('next_measurement_at', $storedDate);
        DB::table('monitoring_schedules')->where('brand_id', $brand->id)->update(['enabled' => false]);
        $this->getJson('/vision/actions/effect')->assertOk()->assertJsonPath('next_measurement_at', null);
        $this->getJson('/vision/actions/effect')->assertOk()->assertJsonPath('recommendations.'.$id.'.priority', 'Высокий')->assertJsonPath('recommendations.'.$id.'.question_ids.0', 'q1')->assertJsonPath('recommendations.'.$id.'.steps.0', 'Шаг');
        $this->post('/vision/actions', [...$input, 'recommendation_index' => 9])->assertSessionHasErrors('recommendation');
        self::assertSame(1, DB::table('actions')->where('brand_id', $brand->id)->count());
        $this->post('/vision/actions', ['title' => 'Неполные данные', 'report_id' => $report])->assertSessionHasErrors('recommendation_index');
        $this->patch('/vision/actions/'.$id, ['title' => 'Переименовано'])->assertRedirect();
        self::assertDatabaseHas('vision_action_recommendation_links', ['action_id' => $id, 'report_id' => $report, 'recommendation_index' => 0]);
        $this->patch('/vision/actions/'.$id, ['status' => 'awaiting_measurement', 'completed_at' => now()->toDateString(), 'comment' => 'Готово'])->assertRedirect()->assertSessionHasNoErrors();
        self::assertDatabaseHas('vision_action_recommendation_links', ['action_id' => $id, 'report_id' => $report, 'recommendation_index' => 0]);
        $this->getJson('/vision/actions/effect')->assertOk()->assertJsonPath('recommendations.'.$id.'.question_ids.0', 'q1');
        $audit = (string) Str::ulid();
        DB::table('audit_runs')->insert(['id' => $audit, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'result' => json_encode(['criteria' => [['id' => 3, 'status' => 'fail'], ['id' => 4, 'status' => 'pass']]]),
            'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/vision/actions', ['title' => 'Исправить критерий', 'audit_id' => $audit, 'criterion_id' => '3'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $created = DB::table('actions')->where('brand_id', $brand->id)->where('title', 'Исправить критерий')->value('id');
        $event = DB::table('audit_logs')->where('subject_id', $created)->where('action', 'vision.action.created_from_site')->first();
        self::assertNotNull($event);
        self::assertSame(['audit_id' => $audit, 'criterion_id' => '3'], json_decode($event->after, true));
        $this->post('/vision/actions', ['title' => 'Недопустимый критерий', 'audit_id' => $audit, 'criterion_id' => '4'])
            ->assertSessionHasErrors('criterion_id');
        self::assertDatabaseMissing('actions', ['title' => 'Недопустимый критерий']);
        $this->post('/vision/actions', ['title' => 'Неполный источник', 'audit_id' => $audit])->assertSessionHasErrors('criterion_id');
        [$foreignViewer] = $this->member($org, 'member', [$other->id]);
        $this->actingAs($foreignViewer)->withSession(['brand_id' => $other->id])->post('/vision/actions', ['title' => 'Чужой аудит', 'audit_id' => $audit, 'criterion_id' => '3'])->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/actions', $input)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/vision/actions', $input)->assertNotFound();
        self::assertSame(0, DB::table('actions')->where('brand_id', $other->id)->count());
    }
}
