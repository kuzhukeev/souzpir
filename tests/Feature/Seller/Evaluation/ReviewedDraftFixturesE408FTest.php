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

final class ReviewedDraftFixturesE408FTest extends TenancyTestCase
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
        $persona = collect(Yaml::parseFile(database_path('seeders/Seller/Evaluation/personas.yaml')))->firstWhere('id', 'unknown_item');
        DB::table('seller_personas')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => 'unknown_item', 'name' => 'Fixture', 'type' => 'edge', 'payload' => json_encode($persona, JSON_UNESCAPED_UNICODE), 'max_turns' => 10, 'created_at' => now(), 'updated_at' => now()]);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => base_path('.local/runtime/e408f-empty')]);
        $url = '/seller/knowledge/'.$draftId.'/scenarios';
        for ($i = 0; $i < 2; $i++) {
            $this->post($url)->assertRedirect()->assertSessionHasNoErrors();
        }
        $runs = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->pluck('turns');
        self::assertCount(2, $runs);
        foreach ($runs as $run) {
            $turns = json_decode($run, true);
            self::assertTrue(collect($turns)->contains(fn ($turn) => $turn['client'] === 'Корсар-8 есть в наличии?' && $turn['agent'] === 'У меня нет проверенной информации о наличии.'));
        }
        $persona['first_message'] = 'Корсар-8 есть в наличии?';
        $persona['behavior'] = 'Изменённый сценарий';
        DB::table('seller_personas')->where('brand_id', $brand->id)->where('key', 'unknown_item')->update(['payload' => json_encode($persona, JSON_UNESCAPED_UNICODE)]);
        $this->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $last = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->orderByDesc('created_at')->orderByDesc('id')->first();
        $changed = json_decode($last->turns, true);
        self::assertSame('Корсар-8 есть в наличии?', $changed[0]['client']);
        self::assertNotSame('У меня нет проверенной информации о наличии.', $changed[0]['agent']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post($url)->assertNotFound();
        Http::assertNothingSent();
    }
}
