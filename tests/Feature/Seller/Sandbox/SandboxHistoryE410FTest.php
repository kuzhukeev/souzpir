<?php

namespace Tests\Feature\Seller\Sandbox;

use App\Ai\CanonicalJson;
use App\Ai\Protected\DraftResponder;
use App\ProtectedOperations\DraftSimulation\FixtureRuntime;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SandboxHistoryE410FTest extends TenancyTestCase
{
    public function test_fixture_answer_is_grounded_guarded_and_brand_scoped(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $prototype = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
        $data = [];
        foreach (PrototypeKnowledge::FILES as $file) {
            $data[$file] = $prototype->all($file);
        }
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $data) {
            $repo = app(KnowledgeRepository::class);
            $created = $repo->createDraft($tenant, $data);

            return $repo->forRelease($tenant, $created['id'], true);
        });
        $client = 'Какой минимальный заказ?';
        $extraction = ['client_intent' => 'ask_min_order', 'slot_updates' => []];
        $dir = storage_path('framework/testing/e409b-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $dir]);
        $runtime = app(FixtureRuntime::class);
        $extractBody = $runtime->body($draft, 'extract', ['client' => $client, 'intents' => $draft->intentIds()]);
        file_put_contents($dir.'/'.CanonicalJson::hash($extractBody).'.json', json_encode(['result' => ['content' => json_encode($extraction)]], JSON_THROW_ON_ERROR));
        $state = new LeadState;
        $policy = new Policy($draft);
        $extraction['_text'] = $client;
        $policy->applyExtraction($state, $extraction);
        $goal = $policy->decide($state, $extraction, true);
        $responderBody = app(DraftResponder::class)->body($draft, $state, $goal, $client);
        $answer = $draft->factsForIntent('ask_min_order')[0]['facts'][0];
        self::assertStringContainsString($answer, $responderBody['input']['system']);
        $examples = $draft->fewshot($goal->fewshotTags, $state->clientType(), 1);
        self::assertNotEmpty($examples);
        self::assertStringContainsString($examples[0]['text'], $responderBody['input']['system']);
        file_put_contents($dir.'/'.CanonicalJson::hash($responderBody).'.json', json_encode(['result' => ['content' => $answer]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $policy->applyExtraction($state, $extraction);
        $secondGoal = $policy->decide($state, $extraction, false);
        $secondBody = app(DraftResponder::class)->body($draft, $state, $secondGoal, $client);
        file_put_contents($dir.'/'.CanonicalJson::hash($secondBody).'.json', json_encode(['result' => ['content' => $answer]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/knowledge/sandbox/reply', ['release' => $draft->releaseId(), 'text' => $client])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('answer', $answer)->assertJsonPath('goal.kind', $goal->kind)->assertJsonPath('goal.allowed_tools', $goal->allowedTools)->assertJsonStructure(['guard' => ['verdict', 'issues', 'warnings']]);
        $this->postJson('/seller/knowledge/sandbox/reply', ['release' => $draft->releaseId(), 'text' => $client, 'history' => [$client]])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('goal.kind', $secondGoal->kind);
        $this->postJson('/seller/knowledge/sandbox/reply', ['release' => $draft->releaseId(), 'text' => $client, 'history' => array_fill(0, 10, $client)])->assertUnprocessable();
        $this->postJson('/seller/knowledge/sandbox/reply', ['release' => $draft->releaseId(), 'text' => $client, 'history' => ['нет записи']])->assertOk()->assertJsonPath('status', 'unavailable');
        $this->postJson('/seller/knowledge/sandbox/reply', ['release' => $draft->releaseId(), 'text' => 'нет записи'])->assertOk()->assertJsonPath('status', 'unavailable');
        $this->postJson('/seller/knowledge/sandbox/reply', ['release' => $draft->releaseId(), 'text' => ''])->assertUnprocessable();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->postJson('/seller/knowledge/sandbox/reply', ['release' => $draft->releaseId(), 'text' => $client])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->postJson('/seller/knowledge/sandbox/reply', ['release' => $draft->releaseId(), 'text' => $client])->assertNotFound();
    }
}
