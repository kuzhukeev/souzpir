<?php

namespace Tests\Feature\Seller\Knowledge;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\Rejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DraftSeedFixturesE401STest extends TenancyTestCase
{
    public function test_full_card_examples_include_evidence_and_leave_unfinished_dialogues_unknown(): void
    {
        $personas = collect(Yaml::parseFile(base_path('database/seeders/Seller/Evaluation/personas.yaml')))->keyBy('id');
        $catalog = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-context-e401s.json')), true, 32, JSON_THROW_ON_ERROR);
        $requirements = ['newbie' => ['city', 'purpose', 'experience', 'outlet_type', 'categories', 'budget_range', 'timing'], 'experienced' => ['city', 'purpose', 'experience', 'categories', 'budget_range', 'timing']];
        $dir = storage_path('framework/testing/e401s-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['features.E0-05.defaults.fixture_directory' => $dir]);
        self::assertCount(26, $catalog);
        foreach ($catalog as $example) {
            self::assertContains('full_card', $personas[$example['persona']]['expect']);
            $card = $example['facts']['card'];
            foreach ($card as $field => $value) {
                self::assertTrue(collect($example['history'])->contains(fn ($turn) => ($turn['field'] ?? null) === $field && $turn['text'] === $value));
            }
            $complete = collect($requirements[$example['facts']['customer_type']])->every(fn ($field) => ! empty($card[$field])) && (! empty($card['email']) || ! empty($card['messenger']));
            self::assertSame($example['expected'], $example['events'] === [] ? null : $complete);
            $input = $example;
            unset($input['expected']);
            $body = ['model' => 'fake-draft-judge', 'draft' => 'fixture', 'checksum' => str_repeat('a', 64), 'input' => $input];
            if ($example['expected'] !== null) {
                $result = ['checks' => ['full_card' => $example['expected']]];
                file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => ['content' => json_encode($result, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR));
                self::assertSame($result, json_decode(app(FakeDriver::class)->complete($body)['content'], true, 32, JSON_THROW_ON_ERROR));
            } else {
                try {
                    app(FakeDriver::class)->complete($body);
                    self::fail('Незавершённый сценарий должен оставаться без оценки.');
                } catch (Rejected) {
                    self::assertTrue(true);
                }
            }
        }
        $body['input']['history'][0]['text'] .= ' изменено';
        $this->expectException(Rejected::class);
        app(FakeDriver::class)->complete($body);
    }

    public function test_all_persona_criteria_have_explicit_context_or_an_unknown_result(): void
    {
        $personas = Yaml::parseFile(base_path('database/seeders/Seller/Evaluation/personas.yaml'));
        $directory = app_path('ProtectedOperations/DraftSimulation/fixtures');
        $catalog = collect();
        foreach (array_merge(glob($directory.'/seeded-judgments*.json'), glob($directory.'/seeded-context*.json')) as $file) {
            $catalog = $catalog->concat(json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR));
        }
        foreach ($personas as $persona) {
            foreach ($persona['expect'] as $criterion) {
                self::assertTrue($catalog->contains(fn ($row) => $row['persona'] === $persona['id'] && $row['criterion'] === $criterion), $persona['id'].':'.$criterion);
            }
        }
        $remaining = json_decode(file_get_contents($directory.'/seeded-context-e401s-remaining.json'), true, 32, JSON_THROW_ON_ERROR);
        $dir = storage_path('framework/testing/e401s-remaining-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['features.E0-05.defaults.fixture_directory' => $dir]);
        foreach ($remaining as $example) {
            self::assertNotEmpty($example['history']);
            self::assertTrue($example['facts']['synthetic']);
            self::assertStringContainsString($example['persona'], $example['facts']['source']);
            $input = $example;
            unset($input['expected']);
            $body = ['model' => 'fake-draft-judge', 'draft' => 'fixture', 'checksum' => str_repeat('a', 64), 'input' => $input];
            if ($example['expected'] === null) {
                self::assertNotEmpty($example['facts']['unknown_reason']);
                try {
                    app(FakeDriver::class)->complete($body);
                    self::fail('Отсутствующий источник не должен давать оценку.');
                } catch (Rejected) {
                    self::assertTrue(true);
                }
            } else {
                $result = ['checks' => [$example['criterion'] => $example['expected']]];
                file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => ['content' => json_encode($result, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR));
                self::assertSame($result, json_decode(app(FakeDriver::class)->complete($body)['content'], true, 32, JSON_THROW_ON_ERROR));
                $body['input']['events'][] = ['type' => 'changed_evidence'];
                try {
                    app(FakeDriver::class)->complete($body);
                    self::fail('Изменённое подтверждение требует отдельной оценки.');
                } catch (Rejected) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function test_draft_scenarios_require_write_access_and_are_brand_scoped(): void
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
