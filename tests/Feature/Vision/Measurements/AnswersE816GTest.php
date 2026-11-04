<?php

namespace Tests\Feature\Vision\Measurements;

use App\Ai\CanonicalJson;
use App\Vision\Core\VisionReportReader;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AnswersE816GTest extends TenancyTestCase
{
    public function test_answers_and_decisions_are_brand_scoped(): void
    {
        [$owner,$org,$brand,$other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $profile = ['site' => 'https://example.test'];
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $answer = (string) Str::ulid();
        $confirmation = (string) Str::ulid();
        $fact = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => json_encode([['id' => 'q1', 'text' => 'Где купить?', 'intent' => 'buy']]), 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => json_encode($profile), 'profile_checksum' => CanonicalJson::hash($profile), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurement_answers')->insert(['id' => $answer, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'question_n' => 1, 'provider' => 'fake', 'requested_model' => 'fake', 'run_no' => 1, 'status' => 'ok', 'answer_text' => 'Пример бренда', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('snapshot_confirmations')->insert(['id' => $confirmation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('answer_facts')->insert(['id' => $fact, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'snapshot_confirmation_id' => $confirmation, 'measurement_answer_id' => $answer, 'answer_text' => 'Пример бренда', 'fact' => json_encode(['identity_candidates' => ['Пример бренда']]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        $gate = Mockery::mock(KnowledgeGate::class);
        $gate->shouldReceive('assertAllowed')->andReturnNull();
        app()->instance(KnowledgeGate::class, $gate);
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->with($report)->andReturn(['metrics' => ['slots' => [['id' => $answer, 'fact' => ['brand_mentioned' => true], 'mentions' => [['name' => 'Пример бренда', 'is_brand' => true]], 'sources' => [['domain' => 'example.test']]]]]]);
        app()->instance(VisionReportReader::class, $reader);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $page = $this->get('/vision/runs/'.$measurement.'/answers')->assertOk();
        $revision = $page->inertiaProps('measurementBrandRevision');
        $url = '/vision/runs/'.$measurement.'/answers/data';
        $this->getJson($url)->assertStatus(409);
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->getJson($url)->assertOk()->assertJsonPath('answers.0.question', 'Где купить?')->assertJsonPath('answers.0.intent', 'buy')->assertJsonPath('answers.0.fact.brand_mentioned', true)->assertJsonPath('answers.0.sources.0.domain', 'example.test');
        $key = hash('sha256', 'mentions:'.$fact.':Пример бренда');
        $this->withHeaders($headers)->post('/vision/runs/'.$measurement.'/clarify/mentions', ['item_key' => $key, 'decision' => 'yes'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vision_clarification_decisions', ['item_key' => $key, 'decision' => 'yes']);
        $this->withHeaders($headers)->getJson($url)->assertOk()->assertJsonPath('answers.0.candidates.0.decision', 'yes');
        $this->withHeaders($headers)->post('/vision/runs/'.$measurement.'/clarify/mentions', ['item_key' => 'bad', 'decision' => 'yes'])->assertSessionHasErrors('item_key');
        $this->withHeaders($headers)->getJson('/vision/runs/'.Str::ulid().'/answers/data')->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/runs/'.$measurement.'/answers')->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/vision/runs/'.$measurement.'/answers')->assertOk();
        $this->post('/vision/runs/'.$measurement.'/clarify/mentions', ['item_key' => $key, 'decision' => 'yes'])->assertForbidden();
    }
}
