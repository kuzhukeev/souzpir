<?php

namespace Tests\Feature\Seller\Knowledge;

use App\Ai\CanonicalJson;
use App\ProtectedOperations\DraftSimulation\FixtureRuntime;
use App\Seller\Evaluation\DraftScenarioPreview;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DraftFixtureRuntimeE401LTest extends TenancyTestCase
{
    public function test_fixture_runtime_uses_each_turn_and_checked_draft(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $persona = ['first_message' => 'Привет', 'facts' => ['Доставка'], 'expect' => ['full_card']];
        DB::table('seller_personas')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'key' => 'fixture', 'name' => 'Тест', 'type' => 'edge', 'payload' => json_encode($persona), 'max_turns' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $id = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->value('id');
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $id) {
            $repository = app(KnowledgeRepository::class);
            $data = $repository->forRelease($tenant, $id, true)->toArray();
            $data['intents'] = [['id' => 'unclear', 'description' => 'Неясный вопрос'], ['id' => 'ask_delivery', 'description' => 'Доставка']];
            $repository->saveDraft($tenant, $id, 1, $data);

            return $repository->forRelease($tenant, $id, true);
        });
        $runtime = app(FixtureRuntime::class);
        $dir = storage_path('framework/testing/e401l-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $dir]);
        $turns = app(DraftScenarioPreview::class)->dialogue($draft, $persona, 2);
        foreach ($turns as $index => $turn) {
            $extract = $runtime->body($draft, 'extract', ['client' => $turn['client'], 'intents' => $draft->intentIds()]);
            $result = ['client_intent' => $index === 0 ? 'ask_delivery' : 'unclear'];
            file_put_contents($dir.'/'.CanonicalJson::hash($extract).'.json', json_encode(['result' => ['content' => json_encode($result)]], JSON_THROW_ON_ERROR));
        }
        $this->post('/seller/knowledge/'.$id.'/scenarios')->assertRedirect();
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->orderByDesc('created_at')->orderByDesc('id')->first();
        $first = json_decode($run->turns, true);
        self::assertSame('ask_delivery', $first[0]['meta']['extracted_intent']);
        self::assertSame('unclear', $first[1]['meta']['extracted_intent']);
        self::assertFalse($first[0]['meta']['semantic_available']);
        self::assertNull($first[0]['meta']['expectation_checks']['full_card']);
        foreach ($first as $turn) {
            $judge = $runtime->body($draft, 'judge', ['client' => $turn['client'], 'answer' => $turn['agent'], 'expectations' => ['full_card']]);
            file_put_contents($dir.'/'.CanonicalJson::hash($judge).'.json', json_encode(['result' => ['content' => json_encode(['checks' => ['full_card' => true]])]], JSON_THROW_ON_ERROR));
        }
        $this->post('/seller/knowledge/'.$id.'/scenarios')->assertRedirect();
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->orderByDesc('created_at')->orderByDesc('id')->first();
        $actual = json_decode($run->turns, true);
        self::assertCount(2, $actual);
        foreach ($actual as $turn) {
            self::assertTrue($turn['meta']['extraction_available']);
            self::assertTrue($turn['meta']['semantic_available']);
            self::assertTrue($turn['meta']['expectation_checks']['full_card']);
        }
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/'.$id.'/scenarios')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/knowledge/'.$id.'/scenarios')->assertNotFound();
    }
}
