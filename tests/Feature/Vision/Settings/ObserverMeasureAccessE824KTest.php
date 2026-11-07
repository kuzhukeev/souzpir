<?php

namespace Tests\Feature\Vision\Settings;

use App\Ai\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ObserverMeasureAccessE824KTest extends TenancyTestCase
{
    public function test_viewer_keeps_measurement_routes_but_not_full_answers_access(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$editor] = $this->member($org, 'member', [$brand->id]);

        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/vision/runs')->assertOk();

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $members = collect($this->get('/vision/settings', ['X-Inertia-Partial-Component' => 'Vision/Settings/Index', 'X-Inertia-Partial-Data' => 'members'])->assertOk()->inertiaProps('members'))->keyBy('id');
        self::assertFalse($members[$viewer->id]['canReadAnswers']);
        self::assertTrue($members[$editor->id]['canReadAnswers']);

        $profile = ['site' => 'https://example.test'];
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => json_encode([['id' => 'q1', 'text' => 'Где купить?', 'intent' => 'buy']]), 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => json_encode($profile), 'profile_checksum' => CanonicalJson::hash($profile), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurement_answers')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'question_n' => 1, 'provider' => 'fake', 'requested_model' => 'fake', 'run_no' => 1, 'status' => 'ok', 'answer_text' => 'Пример бренда', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->getJson('/vision/runs/'.$measurement.'/answers/data')->assertForbidden();
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()->assertJsonPath('data.0.answers.0.answer_text', null);

        $this->actingAs($editor)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()->assertJsonPath('data.0.answers.0.answer_text', 'Пример бренда');
    }
}
