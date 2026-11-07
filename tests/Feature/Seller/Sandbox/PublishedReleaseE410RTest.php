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

final class PublishedReleaseE410RTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_reply_and_check_work_for_published_release_and_stay_brand_scoped(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $prototype = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
        $data = [];
        foreach (PrototypeKnowledge::FILES as $file) {
            $data[$file] = $prototype->all($file);
        }
        $published = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $data) {
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);

            return $repo->publish($tenant, $draft['id'], 1);
        });
        $client = 'Какой минимальный заказ?';
        $extraction = ['client_intent' => 'ask_min_order', 'slot_updates' => []];
        $dir = storage_path('framework/testing/e410r-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $dir]);
        $runtime = app(FixtureRuntime::class);
        $extractBody = $runtime->body($published, 'extract', ['client' => $client, 'intents' => $published->intentIds()]);
        file_put_contents($dir.'/'.CanonicalJson::hash($extractBody).'.json', json_encode(['result' => ['content' => json_encode($extraction)]], JSON_THROW_ON_ERROR));
        $state = new LeadState;
        $policy = new Policy($published);
        $extraction['_text'] = $client;
        $policy->applyExtraction($state, $extraction);
        $goal = $policy->decide($state, $extraction, true);
        $responderBody = app(DraftResponder::class)->body($published, $state, $goal, $client);
        $answer = $published->factsForIntent('ask_min_order')[0]['facts'][0];
        file_put_contents($dir.'/'.CanonicalJson::hash($responderBody).'.json', json_encode(['result' => ['content' => $answer]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/knowledge/sandbox/reply', ['release' => $published->releaseId(), 'text' => $client])
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('answer', $answer)->assertJsonPath('goal.kind', $goal->kind);
        $this->postJson('/seller/knowledge/sandbox/check', ['release' => $published->releaseId(), 'text' => $answer])
            ->assertOk()->assertJsonStructure(['verdict', 'issues', 'warnings']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])
            ->postJson('/seller/knowledge/sandbox/reply', ['release' => $published->releaseId(), 'text' => $client])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])
            ->postJson('/seller/knowledge/sandbox/reply', ['release' => $published->releaseId(), 'text' => $client])->assertNotFound();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])
            ->postJson('/seller/knowledge/sandbox/check', ['release' => $published->releaseId(), 'text' => $answer])->assertNotFound();
    }
}
