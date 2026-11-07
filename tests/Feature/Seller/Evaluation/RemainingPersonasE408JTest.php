<?php

namespace Tests\Feature\Seller\Evaluation;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RemainingPersonasE408JTest extends TenancyTestCase
{
    public function test_remaining_personas_keep_guarded_fallback_without_false_handoff(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draftId = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->value('id');
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id, 'job');
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $draftId): void {
            $repo = app(KnowledgeRepository::class);
            $source = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
            $data = $repo->forRelease($tenant, $draftId, true)->toArray();
            foreach (['policy', 'persona', 'intents', 'guard_rules', 'fewshot'] as $file) {
                $data[$file] = $source->all($file);
            }
            $repo->saveDraft($tenant, $draftId, 1, $data);
        });
        $keys = ['wants_human', 'requisites', 'big_order', 'experienced_kazan', 'for_self_wedding', 'tc_floor', 'prices_only_rude'];
        $personas = collect(Yaml::parseFile(database_path('seeders/Seller/Evaluation/personas.yaml')));
        $catalog = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-judgments-e401q.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($keys as $key) {
            $persona = $personas->firstWhere('id', $key);
            DB::table('seller_personas')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => $key, 'name' => 'Fixture', 'type' => 'edge', 'payload' => json_encode($persona, JSON_UNESCAPED_UNICODE), 'max_turns' => $persona['max_turns'], 'created_at' => now(), 'updated_at' => now()]);
        }
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => base_path('.local/runtime/e408j-empty')]);
        $url = '/seller/knowledge/'.$draftId.'/scenarios';
        $this->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $runs = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->get();
        self::assertCount(count($keys), $runs);
        $missingInputs = [];
        foreach ($keys as $key) {
            $persona = $personas->firstWhere('id', $key);
            $entry = collect($catalog)->first(fn ($item) => ($item['persona'] ?? null) === $key && ($item['expected'] ?? null) === true && in_array($item['criterion'] ?? null, $persona['expect'] ?? [], true));
            self::assertNotNull($entry, $key);
            $run = $runs->first(fn ($row) => DB::table('seller_personas')->where('id', $row->persona_id)->value('key') === $key);
            if ($key === 'requisites') {
                $allTurns = json_decode($run->turns, true);
                self::assertCount(6, $allTurns);
                $clients = implode("\n", array_column($allTurns, 'client'));
                foreach ($persona['facts'] as $fact) {
                    self::assertStringContainsString((string) $fact, $clients);
                }
            }
            $turn = collect(json_decode($run->turns, true))->first(fn ($item) => $item['client'] === $entry['client']);
            if ($turn === null) {
                $missingInputs[] = $key;

                continue;
            }
            if (in_array($key, ['wants_human', 'requisites', 'big_order'], true)) {
                self::assertNotSame($entry['answer'], $turn['agent'], $key);
            }
            if (in_array($key, ['experienced_kazan', 'tc_floor'], true)) {
                self::assertSame($entry['answer'], $turn['agent'], $key);
                self::assertSame('ok', $turn['meta']['guard_verdict'], $key);
            }
            if (in_array($key, ['for_self_wedding', 'prices_only_rude'], true)) {
                self::assertSame($entry['answer'], $turn['agent'], $key);
                self::assertSame('ok', $turn['meta']['guard_verdict'], $key);
            }
            self::assertDoesNotMatchRegularExpression('/переда(?:м|ю|л)|отправ(?:лю|ил)|высл(?:ал|ю)/iu', $turn['agent'], $key);
        }
        self::assertSame([], $missingInputs);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post($url)->assertNotFound();
        Http::assertNothingSent();
    }
}
