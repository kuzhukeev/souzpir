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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SandboxTurnsE407CTest extends TenancyTestCase
{
    public function test_selected_facts_conditions_and_turn_history_are_preserved(): void
    {
        Http::preventStrayRequests();
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
        $first = $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $draft->releaseId(), 'text' => $client])->assertOk()->assertJsonPath('status', 'completed')->assertJsonStructure(['history' => [['created_at', 'state', 'inspector']]])->assertJsonPath('history.0.inspector.intent', 'ask_min_order')->assertJsonPath('history.0.inspector.selected_facts.0', $answer)->assertJsonPath('history.0.inspector.selected_conditions.min_order_rub', $draft->conditions()['min_order_rub'])->json();
        $second = $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $draft->releaseId(), 'text' => $client])->assertOk()->assertJsonCount(2, 'history')->assertJsonPath('history.1.inspector.previous_answer', $first['history'][0]['answer'])->json();
        $this->assertSame($first['history'][0]['state'], $second['history'][0]['state']);
        $this->assertSame($first['history'][0]['inspector'], $second['history'][0]['inspector']);
        $this->get('/seller/knowledge/sandbox')->assertOk()->assertInertia(fn ($page) => $page->has('sandboxSession.history', 2)->where('sandboxSession.history.0.inspector.intent', 'ask_min_order'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/sandbox')->assertOk()->assertInertia(fn ($page) => $page->where('sandboxSession', null));
        Http::assertNothingSent();
    }
}
