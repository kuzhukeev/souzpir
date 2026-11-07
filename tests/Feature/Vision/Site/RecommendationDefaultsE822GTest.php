<?php

namespace Tests\Feature\Vision\Site;

use App\Tenancy\TenantContext;
use App\Vision\Actions\RecommendationLink;
use App\Vision\Core\VisionReportReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RecommendationDefaultsE822GTest extends TenancyTestCase
{
    public function test_priority_and_steps_are_derived_when_missing(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->post('/test-recommendation-link', function (Request $request) {
            app(RecommendationLink::class)->attach(app(TenantContext::class), $request, $request->input('action'), $request->input('report'), (int) $request->input('index'));

            return response()->noContent();
        });
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => '[{"id":"q1","text":"В1"},{"id":"q2","text":"В2"}]', 'size' => 2, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->with($report)->andReturn(['recommendations' => ['items' => [
            ['title' => 'Две затронутых', 'question_ids' => ['q1', 'q2']],
            ['title' => 'Одна затронутая', 'question_ids' => ['q1']],
            ['title' => 'Без вопросов'],
        ]]]);
        $this->app->instance(VisionReportReader::class, $reader);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $ids = [];
        foreach ([0, 1, 2] as $index) {
            $id = (string) Str::ulid();
            DB::table('actions')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Действие '.$index, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            $this->postJson('/test-recommendation-link', ['action' => $id, 'report' => $report, 'index' => $index])->assertNoContent();
            $ids[$index] = $id;
        }
        $response = $this->getJson('/vision/actions/effect')->assertOk();
        $response->assertJsonPath('recommendations.'.$ids[0].'.priority', 'Высокая')
            ->assertJsonPath('recommendations.'.$ids[0].'.steps.0', 'Проверьте описание')
            ->assertJsonPath('recommendations.'.$ids[1].'.priority', 'Средняя')
            ->assertJsonPath('recommendations.'.$ids[1].'.steps.0', 'Проверьте описание')
            ->assertJsonPath('recommendations.'.$ids[2].'.priority', 'Обычная')
            ->assertJsonPath('recommendations.'.$ids[2].'.steps.0', 'Изучите рекомендацию');
    }
}
