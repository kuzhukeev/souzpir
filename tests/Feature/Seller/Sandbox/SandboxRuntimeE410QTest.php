<?php

namespace Tests\Feature\Seller\Sandbox;

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

final class SandboxRuntimeE410QTest extends TenancyTestCase
{
    public function test_fake_dialogue_preserves_state_context_and_isolates_brand(): void
    {
        Http::preventStrayRequests();
        config(['runtime.ai_driver' => 'fake']);
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
        [$draft, $published] = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $data): array {
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $published = $repo->publish($tenant, $draft['id'], 1);

            return [$draft['id'], $published->releaseId()];
        });
        $client = (string) Str::ulid();
        $foreignClient = (string) Str::ulid();
        foreach ([[$client, $brand], [$foreignClient, $other]] as [$id, $item]) {
            DB::table('contacts')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $item->id, 'name' => 'Test', 'external_ids' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $channel = (string) Str::ulid();
        DB::table('channel_accounts')->insert(['id' => $channel, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'api', 'name' => 'Test API', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $snapshot = app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->forRelease($tenant, $draft, true));
        $state = new LeadState;
        $state->setSlot('name', 'Test');
        $policy = new Policy($snapshot);
        $policy->applyExtraction($state, ['client_intent' => 'unclear', 'slot_updates' => ['city' => 'Воронеж'], '_text' => 'Уточню: Воронеж']);
        $goal = $policy->decide($state, ['client_intent' => 'unclear', 'slot_updates' => ['city' => 'Воронеж'], '_text' => 'Уточню: Воронеж'], true);
        $body = app(DraftResponder::class)->body($snapshot, $state, $goal, 'Уточню: Воронеж');
        $dir = storage_path('framework/testing/e410q-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['features.E0-05.defaults.fixture_directory' => $dir]);
        file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => ['content' => 'Для чего планируете покупку?']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = ['simulation' => true, 'release' => $draft, 'client_id' => $client, 'channel_id' => $channel, 'text' => 'Уточню: Воронеж'];
        $first = $this->postJson('/seller/knowledge/sandbox/reply', $input)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('state.slots.city', 'Воронеж')->assertJsonPath('state.slots.name', 'Test')->assertJsonPath('inspector.client', 'Test')->assertJsonPath('inspector.channel', 'Test API')->assertJsonPath('inspector.response_source', 'fixture')->json();
        $this->postJson('/seller/knowledge/sandbox/reply', [...$input, 'text' => 'Уточню: для себя'])->assertOk()->assertJsonPath('state.slots.city', 'Воронеж')->assertJsonPath('state.slots.purpose', 'для себя')->assertJsonPath('inspector.previous_answer', $first['history'][0]['answer'])->assertJsonCount(2, 'history')->assertJsonCount(0, 'inspector.executed_tools');
        $this->get('/seller/knowledge/sandbox')->assertOk()->assertInertia(fn ($page) => $page->has('clients', 1)->has('channels', 1)->has('sandboxSession.history', 2));
        $this->post('/seller/quality/scenarios', ['name' => 'Тест из песочницы', 'type' => 'edge', 'behavior' => 'Клиент: Уточню: Воронеж', 'first_message' => 'Уточню: Воронеж', 'max_turns' => 2, 'expect' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('seller_personas', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'Тест из песочницы']);
        $scenario = json_decode(DB::table('seller_personas')->where('organization_id', $org->id)->where('brand_id', $brand->id)->where('name', 'Тест из песочницы')->value('payload'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('Уточню: Воронеж', $scenario['first_message']);
        self::assertSame('Клиент: Уточню: Воронеж', $scenario['behavior']);

        $this->postJson('/seller/knowledge/sandbox/reply', [...$input, 'text' => 'Повтори'])->assertOk()->assertJsonCount(3, 'history');
        $this->postJson('/seller/knowledge/sandbox/reply', [...$input, 'start_new' => true])->assertOk()->assertJsonCount(1, 'history');
        $this->postJson('/seller/knowledge/sandbox/reply', [...$input, 'client_id' => $foreignClient])->assertNotFound();
        $this->postJson('/seller/knowledge/sandbox/reply', [...$input, 'channel_id' => (string) Str::ulid()])->assertNotFound();
        config(['runtime.ai_driver' => 'openrouter']);
        $this->postJson('/seller/knowledge/sandbox/reply', $input)->assertOk()->assertJsonPath('status', 'unavailable');
        config(['runtime.ai_driver' => 'fake']);
        $this->postJson('/seller/knowledge/sandbox/reply', [...$input, 'release' => $published])->assertOk()->assertJsonPath('status', 'completed')->assertJsonCount(1, 'history');
        $this->postJson('/seller/knowledge/sandbox/reply', [...$input, 'release' => $published, 'reset' => true])->assertOk()->assertJsonPath('status', 'reset');
        $this->get('/seller/knowledge/sandbox')->assertInertia(fn ($page) => $page->where('sandboxSession', null));
        self::assertSame(0, DB::table('conversations')->count());
        self::assertSame(0, DB::table('messages')->count());
        Http::assertNothingSent();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->postJson('/seller/knowledge/sandbox/reply', $input)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->postJson('/seller/knowledge/sandbox/reply', $input)->assertNotFound();
    }
}
