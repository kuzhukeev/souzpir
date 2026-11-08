<?php

namespace Tests\Feature\Seller\Evaluation;

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

final class ConfirmedHandoffE408KTest extends TenancyTestCase
{
    public function test_recorded_fake_tool_is_required_before_handoff_claim(): void
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
        $client = 'Соедините с менеджером';
        $arguments = ['reason' => 'want_human'];
        $bodies = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $draftId, $client, $arguments): array {
            $repo = app(KnowledgeRepository::class);
            $source = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
            $data = $repo->forRelease($tenant, $draftId, true)->toArray();
            foreach (['policy', 'persona', 'intents', 'guard_rules', 'fewshot', 'escalation'] as $file) {
                $data[$file] = $source->all($file);
            }
            $repo->saveDraft($tenant, $draftId, 1, $data);
            $draft = $repo->forRelease($tenant, $draftId, true);
            $extraction = ['client_intent' => 'want_human', 'slot_updates' => [], '_text' => $client];
            $state = new LeadState;
            $policy = new Policy($draft);
            $policy->applyExtraction($state, $extraction);
            $goal = $policy->decide($state, $extraction, true);
            self::assertContains('escalate', $goal->allowedTools);
            $runtime = app(FixtureRuntime::class);

            return [$runtime->body($draft, 'extract', ['client' => $client, 'intents' => $draft->intentIds()]),
                app(DraftResponder::class)->body($draft, $state, $goal, $client),
                $runtime->body($draft, 'tool', ['name' => 'escalate', 'arguments' => $arguments])];
        });
        $dir = base_path('.local/runtime/e408k-fixtures');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $dir]);
        $write = fn (array $body, array $result) => file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $write($bodies[0], ['content' => json_encode(['client_intent' => 'want_human', 'slot_updates' => []], JSON_THROW_ON_ERROR)]);
        $write($bodies[1], ['content' => 'Передам запрос менеджеру.', 'toolCalls' => [['name' => 'escalate', 'arguments' => $arguments]]]);
        $write($bodies[2], ['content' => json_encode(['name' => 'escalate', 'status' => 'fake_completed'], JSON_THROW_ON_ERROR)]);
        foreach (['wants_human', 'requisites', 'big_order'] as $key) {
            DB::table('seller_personas')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => $key, 'name' => 'fixture', 'type' => 'edge', 'payload' => json_encode(['first_message' => $client]), 'max_turns' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $url = '/seller/knowledge/'.$draftId.'/scenarios';
        $this->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $runs = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->pluck('turns');
        self::assertCount(3, $runs);
        foreach ($runs as $raw) {
            $turn = json_decode($raw, true)[0];
            self::assertSame('В тестовом сценарии запрос передан менеджеру.', $turn['agent']);
            self::assertSame([['name' => 'escalate', 'status' => 'fake_completed', 'arguments' => $arguments]], $turn['meta']['tool_results']);
            self::assertSame('ok', $turn['meta']['guard_verdict']);
        }
        self::assertDatabaseCount('conversations', 0);
        unlink($dir.'/'.CanonicalJson::hash($bodies[2]).'.json');
        $this->post($url)->assertRedirect()->assertSessionHasNoErrors();
        $runs = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->pluck('turns');
        self::assertCount(6, $runs);
        self::assertSame(3, $runs->filter(fn ($raw) => json_decode($raw, true)[0]['agent'] === 'В тестовом сценарии запрос менеджеру не передаётся.')->count());
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post($url)->assertForbidden();
        Http::assertNothingSent();
    }
}
