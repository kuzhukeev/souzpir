<?php

namespace Tests\Feature\Seller\Knowledge;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\Rejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DraftSeedFixturesE401QTest extends TenancyTestCase
{
    public function test_reviewed_pairs_cover_personas_and_unknown_input_is_rejected(): void
    {
        $personas = collect(Yaml::parseFile(base_path('database/seeders/Seller/Evaluation/personas.yaml')))->keyBy('id');
        $catalog = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-judgments-e401q.json')), true, 32, JSON_THROW_ON_ERROR);
        self::assertCount(64, $catalog);
        self::assertCount(28, array_unique(array_column($catalog, 'persona')));
        $dir = storage_path('framework/testing/e401q-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['features.E0-05.defaults.fixture_directory' => $dir]);
        $seen = [];
        foreach ($catalog as $example) {
            self::assertContains($example['criterion'], $personas[$example['persona']]['expect']);
            $body = ['model' => 'fake-draft-judge', 'draft' => 'fixture', 'checksum' => str_repeat('a', 64), 'input' => ['client' => $example['client'], 'answer' => $example['answer'], 'expectations' => [$example['criterion']]]];
            $result = ['checks' => [$example['criterion'] => $example['expected']]];
            file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => ['content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR));
            self::assertSame($result, json_decode(app(FakeDriver::class)->complete($body)['content'], true, 32, JSON_THROW_ON_ERROR));
            $seen[$example['persona'].'/'.$example['criterion']][] = $example['expected'];
        }
        foreach ($seen as $values) {
            sort($values);
            self::assertSame([false, true], $values);
        }
        $body['input']['answer'] .= ' изменено';
        $this->expectException(Rejected::class);
        app(FakeDriver::class)->complete($body);
    }

    public function test_scenario_requires_rights_and_is_isolated_by_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/draft')->assertRedirect();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->value('id');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/'.$draft.'/scenarios')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/knowledge/'.$draft.'/scenarios')->assertNotFound();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/'.Str::ulid().'/scenarios')->assertNotFound();
    }
}
