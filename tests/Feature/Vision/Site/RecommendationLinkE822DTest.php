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

final class RecommendationLinkE822DTest extends TenancyTestCase
{
    public function test_link_is_bound_to_confirmed_report_and_current_brand(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'tenant'])->post('/test-recommendation-link', function (Request $request) {
            app(RecommendationLink::class)->attach(app(TenantContext::class), $request, $request->input('action'), $request->input('report'), (int) $request->input('index'));

            return response()->noContent();
        });
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
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->with($report)->andReturn(['recommendations' => ['items' => [['title' => 'Исходное название', 'priority' => 'Высокий', 'steps' => ['Шаг'], 'question_ids' => ['q1']]]]]);
        $this->app->instance(VisionReportReader::class, $reader);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $id = (string) Str::ulid();
        DB::table('actions')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'title' => 'Новое название', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $input = ['action' => $id, 'report' => $report, 'index' => 0];
        $this->postJson('/test-recommendation-link', $input)->assertNoContent();
        $this->getJson('/vision/actions/effect')->assertOk()->assertJsonPath('recommendations.'.$id.'.priority', 'Высокий')->assertJsonPath('recommendations.'.$id.'.question_ids.0', 'q1')->assertJsonPath('recommendations.'.$id.'.steps.0', 'Шаг');
        $this->postJson('/test-recommendation-link', [...$input, 'index' => 9])->assertUnprocessable();
        $this->postJson('/test-recommendation-link', [...$input, 'report' => (string) Str::ulid()])->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->postJson('/test-recommendation-link', $input)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->getJson('/vision/actions/effect')->assertOk()->assertJsonCount(0, 'recommendations');
        $this->postJson('/test-recommendation-link', $input)->assertNotFound();
    }
}
