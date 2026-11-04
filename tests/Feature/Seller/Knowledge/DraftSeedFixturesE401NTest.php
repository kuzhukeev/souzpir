<?php

namespace Tests\Feature\Seller\Knowledge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DraftSeedFixturesE401NTest extends TenancyTestCase
{
    public function test_seeded_persona_uses_checked_data_without_fabricating_first_message(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $personas = Yaml::parseFile(base_path('database/seeders/Seller/Evaluation/personas.yaml'));
        $persona = collect($personas)->firstWhere('id', 'newbie_voronezh');
        DB::table('seller_personas')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'key' => $persona['id'], 'name' => $persona['name'], 'type' => $persona['type'], 'payload' => json_encode($persona), 'max_turns' => 12, 'created_at' => now(), 'updated_at' => now()]);
        $dir = storage_path('framework/testing/e401n-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $dir]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->value('id');
        $this->post('/seller/knowledge/'.$draft.'/scenarios')->assertRedirect();
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->first();
        $turns = json_decode($run->turns, true);
        self::assertSame('Уточню: Воронеж', $turns[0]['client']);
        self::assertSame('Воронеж', $turns[0]['meta']['extracted_slots']['city']);
        self::assertTrue(collect($turns)->contains(fn ($turn) => ($turn['meta']['extracted_slots']['purpose'] ?? null) === 'на продажу'));
        self::assertFalse($turns[0]['meta']['semantic_available']);
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->where('scenarioRun.passed', 0));
        $persona['expect'] = ['no_html'];
        DB::table('seller_personas')->where('brand_id', $brand->id)->where('key', $persona['id'])->update(['payload' => json_encode($persona)]);
        $this->post('/seller/knowledge/'.$draft.'/scenarios')->assertRedirect();
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->orderByDesc('created_at')->orderByDesc('id')->first();
        $turns = json_decode($run->turns, true);
        self::assertTrue($turns[0]['meta']['semantic_available']);
        self::assertTrue($turns[0]['meta']['expectation_checks']['no_html']);
        $persona['facts']['city'] = 'для себя';
        DB::table('seller_personas')->where('brand_id', $brand->id)->where('key', $persona['id'])->update(['payload' => json_encode($persona)]);
        $this->post('/seller/knowledge/'.$draft.'/scenarios')->assertRedirect();
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->orderByDesc('created_at')->orderByDesc('id')->first();
        self::assertFalse(json_decode($run->turns, true)[0]['meta']['extraction_available']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/'.$draft.'/scenarios')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/knowledge/'.$draft.'/scenarios')->assertNotFound();
    }

    public function test_fixture_catalog_matches_seeded_persona_fields(): void
    {
        $personas = collect(Yaml::parseFile(base_path('database/seeders/Seller/Evaluation/personas.yaml')))->keyBy('id');
        $fixtures = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-personas.json')), true, 32, JSON_THROW_ON_ERROR);
        self::assertCount(28, $fixtures);
        foreach ($fixtures as $key => $fields) {
            foreach ($fields as $field => $entry) {
                self::assertSame($personas[$key]['facts'][$field], $entry['value']);
            }
        }
    }
}
