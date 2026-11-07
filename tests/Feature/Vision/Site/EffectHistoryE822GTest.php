<?php

namespace Tests\Feature\Vision\Site;

use App\Vision\Core\VisionReportReader;
use App\Vision\Site\History;
use App\Vision\Site\SiteAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class EffectHistoryE822GTest extends TenancyTestCase
{
    public function test_history_shows_measurement_numbers_description_and_growth_status(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $set = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => '[{"id":"q1","text":"В1"}]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);

        $reader = Mockery::mock(app(VisionReportReader::class));
        $payload = fn (float $visibility) => ['question_set_checksum' => 'checksum', 'providers' => ['p1'], 'runs' => 1,
            'metrics' => ['visibility' => ['macro_percent' => $visibility], 'panel' => ['share_of_voice' => null], 'providers' => [], 'intents' => [], 'entities' => []]];
        foreach ([['2026-10-01', 30.0], ['2026-10-08', 45.0]] as [$date, $visibility]) {
            $measurement = (string) Str::ulid();
            $report = (string) Str::ulid();
            DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => $date, 'updated_at' => $date]);
            DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => $date, 'updated_at' => $date]);
            DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => $date, 'updated_at' => $date]);
            $reader->shouldReceive('read')->with($report)->andReturn($payload($visibility));
        }
        $this->app->instance(History::class, new History(app(VisionReportReader::class), app(SiteAccess::class)));
        $this->app->instance(VisionReportReader::class, $reader);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/actions', ['title' => 'Обновить описание доставки', 'description' => 'Изменили условия и сроки доставки'])->assertSessionHasNoErrors();
        $id = DB::table('actions')->where('brand_id', $brand->id)->value('id');
        $this->patch('/vision/actions/'.$id, ['status' => 'awaiting_measurement', 'completed_at' => '2026-10-04', 'comment' => 'Готово'])->assertSessionHasNoErrors();

        $this->getJson('/vision/actions/effect')->assertOk()
            ->assertJsonPath('items.0.id', $id)
            ->assertJsonPath('items.0.description', 'Изменили условия и сроки доставки')
            ->assertJsonPath('items.0.before_number', 1)
            ->assertJsonPath('items.0.after_number', 2)
            ->assertJsonPath('items.0.status', 'growth')
            ->assertJsonPath('items.0.delta', 15);
    }

    public function test_action_without_later_measurement_is_too_early_with_before_number_only(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $set = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => '[{"id":"q1","text":"В1"}]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01']);
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->with($report)->andReturn(['question_set_checksum' => 'checksum', 'providers' => ['p1'], 'runs' => 1,
            'metrics' => ['visibility' => ['macro_percent' => 40.0], 'panel' => ['share_of_voice' => null], 'providers' => [], 'intents' => [], 'entities' => []]]);
        $this->app->instance(History::class, new History(app(VisionReportReader::class), app(SiteAccess::class)));
        $this->app->instance(VisionReportReader::class, $reader);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/actions', ['title' => 'Обновить страницу каталога'])->assertSessionHasNoErrors();
        $id = DB::table('actions')->where('brand_id', $brand->id)->value('id');
        $this->patch('/vision/actions/'.$id, ['status' => 'awaiting_measurement', 'completed_at' => '2026-10-27', 'comment' => 'Готово'])->assertSessionHasNoErrors();

        $this->getJson('/vision/actions/effect')->assertOk()
            ->assertJsonPath('items.0.status', 'too_early')
            ->assertJsonPath('items.0.delta', null)
            ->assertJsonPath('items.0.before_number', 1)
            ->assertJsonPath('items.0.after_number', null);
    }
}
