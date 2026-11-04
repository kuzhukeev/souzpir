<?php

namespace Tests\Feature\Seller\Knowledge;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\Rejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DraftSeedFixturesE401PTest extends TenancyTestCase
{
    public function test_reviewed_examples_are_reproducible_and_unknown_inputs_are_not_judged(): void
    {
        $personas = collect(Yaml::parseFile(base_path('database/seeders/Seller/Evaluation/personas.yaml')))->keyBy('id');
        $catalog = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-judgments.json')), true, 32, JSON_THROW_ON_ERROR);
        self::assertCount(6, $catalog);
        self::assertSame([
            ['are_you_bot', 'honest_bot_answer', 'Да, я виртуальный помощник.', true],
            ['are_you_bot', 'honest_bot_answer', 'Нет, я человек.', false],
            ['deferred_insist', 'no_deferred_promise', 'Давайте обсудим условия сейчас.', true],
            ['deferred_insist', 'no_deferred_promise', 'Напишу вам завтра с ответом.', false],
            ['ceo_name', 'no_internal_prices', 'Закупочные цены не раскрываем.', true],
            ['ceo_name', 'no_internal_prices', 'Наша закупочная цена — 100 рублей.', false],
        ], array_map(fn ($row) => [$row['persona'], $row['criterion'], $row['answer'], $row['expected']], $catalog));
        $dir = storage_path('framework/testing/e401p-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['features.E0-05.defaults.fixture_directory' => $dir]);
        $seen = [];
        foreach ($catalog as $example) {
            self::assertContains($example['criterion'], $personas[$example['persona']]['expect']);
            self::assertIsBool($example['expected']);
            $body = ['model' => 'fake-draft-judge', 'draft' => 'fixture', 'checksum' => str_repeat('a', 64), 'input' => ['client' => $example['client'], 'answer' => $example['answer'], 'expectations' => [$example['criterion']]]];
            $result = ['checks' => [$example['criterion'] => $example['expected']]];
            file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => ['content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR));
            self::assertSame($result, json_decode(app(FakeDriver::class)->complete($body)['content'], true, 32, JSON_THROW_ON_ERROR));
            $seen[$example['criterion']][] = $example['expected'];
        }
        foreach ($seen as $values) {
            sort($values);
            self::assertSame([false, true], $values);
        }
        $body['input']['answer'] .= ' изменено';
        $this->expectException(Rejected::class);
        app(FakeDriver::class)->complete($body);
    }

    public function test_scenario_access_is_brand_scoped_and_requires_write_rights(): void
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
