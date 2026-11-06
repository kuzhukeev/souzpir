<?php

namespace Tests\Feature\Seller\Evaluation;

use App\Ai\CanonicalJson;
use App\Ai\Protected\DraftResponder;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class HandoffPreviewE408JTest extends TenancyTestCase
{
    public function test_fake_handoff_claim_is_not_presented_as_completed(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $draftId = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->value('id');
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id, 'job');
        $body = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $draftId): array {
            $repo = app(KnowledgeRepository::class);
            $source = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
            $initial = $repo->forRelease($tenant, $draftId, true)->toArray();
            foreach (['policy', 'persona', 'intents', 'guard_rules', 'fewshot'] as $file) {
                $initial[$file] = $source->all($file);
            }
            $repo->saveDraft($tenant, $draftId, 1, $initial);
            $draft = $repo->forRelease($tenant, $draftId, true);
            $state = new LeadState;
            $extraction = ['client_intent' => 'unclear'];
            $policy = new Policy($draft);
            $policy->applyExtraction($state, $extraction);
            $goal = $policy->decide($state, $extraction, true);
            $data = $draft->toArray();
            $data['fewshot']['examples'] = [['tag' => $goal->fewshotTags[0] ?? 'unused', 'text' => 'Положительный пример', 'confidence' => 1, 'enabled' => true]];
            $data['fewshot']['bad_examples'] = [['text' => 'Нежелательный пример', 'why' => 'Некорректно']];
            $repo->saveDraft($tenant, $draftId, 2, $data);
            $draft = $repo->forRelease($tenant, $draftId, true);
            $state = new LeadState;
            $policy = new Policy($draft);
            $policy->applyExtraction($state, $extraction);
            $goal = $policy->decide($state, $extraction, true);

            return app(DraftResponder::class)->body($draft, $state, $goal, 'Уникальный тестовый вопрос');
        });
        self::assertStringContainsString('Положительный пример', $body['input']['system']);
        self::assertStringContainsString('Нежелательный пример', $body['input']['system']);
        $dir = base_path('.local/runtime/e408j-handoff-fixtures');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $dir]);
        file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => ['content' => 'Передам ваш запрос менеджеру.']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        foreach (['wants_human', 'requisites', 'big_order'] as $key) {
            DB::table('seller_personas')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => $key, 'name' => 'fixture', 'type' => 'edge', 'payload' => json_encode(['first_message' => 'Уникальный тестовый вопрос']), 'max_turns' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $url = '/seller/knowledge/'.$draftId.'/scenarios';
        $this->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $turns = json_decode(DB::table('seller_sim_runs')->where('brand_id', $brand->id)->latest('created_at')->value('turns'), true);
        self::assertSame('В тестовом сценарии запрос менеджеру не передаётся.', $turns[0]['agent']);
        self::assertSame('ok', $turns[0]['meta']['guard_verdict']);
        $this->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $results = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->pluck('turns');
        self::assertCount(6, $results);
        foreach ($results as $result) {
            self::assertSame('В тестовом сценарии запрос менеджеру не передаётся.', json_decode($result, true)[0]['agent']);
        }
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post($url)->assertNotFound();
        Http::assertNothingSent();
    }
}
