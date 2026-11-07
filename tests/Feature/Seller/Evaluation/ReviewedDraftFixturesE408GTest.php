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

final class ReviewedDraftFixturesE408GTest extends TenancyTestCase
{
    public function test_reviewed_seeded_response_repeats_and_changed_persona_does_not_use_it(): void
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
        $keys = ['container_import', 'defect_claim', 'car_seller', 'competitor_bait', 'price_increase_doubt', 'legal_questions', 'busy_now', 'rewrite_conditions'];
        $personas = collect(Yaml::parseFile(database_path('seeders/Seller/Evaluation/personas.yaml')));
        $catalog = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-judgments-e401q.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($keys as $key) {
            $persona = $personas->firstWhere('id', $key);
            DB::table('seller_personas')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => $key, 'name' => 'Fixture', 'type' => 'edge', 'payload' => json_encode($persona, JSON_UNESCAPED_UNICODE), 'max_turns' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => base_path('.local/runtime/e408g-empty')]);
        $url = '/seller/knowledge/'.$draftId.'/scenarios';
        for ($i = 0; $i < 2; $i++) {
            $this->post($url)->assertRedirect()->assertSessionHasNoErrors();
        }
        $runs = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->get();
        self::assertCount(count($keys) * 2, $runs);
        foreach ($keys as $key) {
            $persona = $personas->firstWhere('id', $key);
            $entry = collect($catalog)->first(fn ($item) => ($item['persona'] ?? null) === $key && ($item['expected'] ?? null) === true && in_array($item['criterion'] ?? null, $persona['expect'] ?? [], true));
            self::assertNotNull($entry, $key);
            $matching = $runs->filter(fn ($run) => collect(json_decode($run->turns, true))->contains(fn ($turn) => $turn['client'] === $entry['client'] && $turn['agent'] === $entry['answer']));
            self::assertCount(2, $matching, $key);
        }
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $draftId): void {
            $repo = app(KnowledgeRepository::class);
            $snapshot = $repo->forRelease($tenant, $draftId, true);
            $data = $snapshot->toArray();
            $data['persona']['stop_phrases'][] = 'не буду сейчас отвлекать';
            $repo->saveDraft($tenant, $draftId, 2, $data);
        });
        $this->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $recent = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->orderByDesc('created_at')->orderByDesc('id')->limit(count($keys))->get();
        $busy = collect($catalog)->first(fn ($entry) => ($entry['persona'] ?? null) === 'busy_now' && ($entry['expected'] ?? null) === true);
        self::assertFalse($recent->contains(fn ($run) => collect(json_decode($run->turns, true))->contains(fn ($turn) => $turn['client'] === $busy['client'] && $turn['agent'] === $busy['answer'])));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post($url)->assertNotFound();
        Http::assertNothingSent();
    }
}
