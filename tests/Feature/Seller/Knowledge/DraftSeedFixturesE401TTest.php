<?php

namespace Tests\Feature\Seller\Knowledge;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\Rejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DraftSeedFixturesE401TTest extends TenancyTestCase
{
    public function test_complete_dialogues_keep_persona_sources_and_unknown_outcomes(): void
    {
        $directory = app_path('ProtectedOperations/DraftSimulation/fixtures');
        $rows = json_decode(file_get_contents($directory.'/seeded-context-e401t.json'), true, 32, JSON_THROW_ON_ERROR);
        $previous = collect(json_decode(file_get_contents($directory.'/seeded-context-e401s-remaining.json'), true, 32, JSON_THROW_ON_ERROR))
            ->keyBy(fn ($row) => $row['persona'].':'.$row['criterion']);
        $personas = collect(Yaml::parseFile(base_path('database/seeders/Seller/Evaluation/personas.yaml')))->keyBy('id');
        self::assertCount(35, $rows);
        foreach ($rows as $row) {
            $old = $previous[$row['persona'].':'.$row['criterion']];
            $persona = $personas[$row['persona']];
            self::assertContains($row['criterion'], $persona['expect']);
            self::assertSame($old['expected'], $row['expected']);
            self::assertSame($old['client'], $row['client']);
            self::assertSame($old['answer'], $row['answer']);
            self::assertSame($old['events'], $row['events']);
            self::assertGreaterThanOrEqual(4, count($row['history']));
            self::assertCount(count($row['history']) + 2, $row['dialogue']);
            self::assertSame($row['history'], array_slice($row['dialogue'], 0, -2));
            self::assertSame(['role' => 'client', 'text' => $row['client']], $row['dialogue'][count($row['dialogue']) - 2]);
            self::assertSame(['role' => 'agent', 'text' => $row['answer']], $row['dialogue'][count($row['dialogue']) - 1]);
            self::assertTrue(collect($row['history'])->contains(fn ($turn) => ($turn['field'] ?? null) === 'city' && str_contains($turn['text'], (string) $persona['facts']['city'])));
            self::assertTrue($row['facts']['synthetic']);
            self::assertCount(3, $row['facts']['sources']);
            self::assertStringContainsString($row['persona'], $row['facts']['sources'][0]);
            self::assertStringContainsString($row['criterion'], $row['facts']['sources'][1]);
            if ($row['expected'] === null) {
                self::assertSame('unknown', $row['facts']['evidence_status']);
                self::assertNotEmpty($row['facts']['unknown_reason']);
            } else {
                self::assertSame('supported', $row['facts']['evidence_status']);
            }
        }
        $dir = storage_path('framework/testing/e401t-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['features.E0-05.defaults.fixture_directory' => $dir]);
        $supported = collect($rows)->firstWhere('expected', true);
        $input = $supported;
        unset($input['expected'], $input['dialogue']);
        $body = ['model' => 'fake-draft-judge', 'draft' => 'fixture', 'checksum' => str_repeat('a', 64), 'input' => $input];
        $result = ['checks' => [$supported['criterion'] => true]];
        file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => ['content' => json_encode($result, JSON_THROW_ON_ERROR)]], JSON_THROW_ON_ERROR));
        self::assertSame($result, json_decode(app(FakeDriver::class)->complete($body)['content'], true, 32, JSON_THROW_ON_ERROR));
        $body['input']['history'][2]['text'] .= ' изменено';
        $this->expectException(Rejected::class);
        app(FakeDriver::class)->complete($body);
    }

    public function test_draft_scenario_endpoint_keeps_write_and_brand_boundaries(): void
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
