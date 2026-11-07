<?php

namespace Tests\Feature\Seller\Engine;

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

final class PublishedFieldsSandboxReplyE610ETest extends TenancyTestCase
{
    public function test_sandbox_reply_keeps_published_additional_field_from_fixture_extraction(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $prototype = new PrototypeKnowledge(base_path('tests/Feature/Seller/Knowledge/Fixtures/data'), 0);
        $data = [];
        foreach (PrototypeKnowledge::FILES as $file) {
            $data[$file] = $prototype->all($file);
        }
        $data['persona']['company'] = 'Онлайн-школа';
        $data['policy']['client_fields'] = [
            ['id' => 'course', 'name' => 'Курс', 'type' => 'text', 'options' => [], 'required' => true, 'stage' => 'need'],
            ['id' => 'level', 'name' => 'Уровень', 'type' => 'select', 'options' => ['Начальный', 'Продвинутый'], 'required' => false, 'stage' => null],
        ];
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $data) {
            $repo = app(KnowledgeRepository::class);
            $created = $repo->createDraft($tenant, $data);

            return $repo->forRelease($tenant, $created['id'], true);
        });
        $dir = storage_path('framework/testing/e610e-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $dir]);
        $runtime = app(FixtureRuntime::class);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);

        $client = 'Хочу на Английский, продвинутый уровень.';
        $extraction = ['client_intent' => 'unclear', 'slot_updates' => ['course' => 'Английский', 'level' => 'Продвинутый']];
        $extractBody = $runtime->body($draft, 'extract', ['client' => $client, 'intents' => $draft->intentIds()]);
        file_put_contents($dir.'/'.CanonicalJson::hash($extractBody).'.json', json_encode(['result' => ['content' => json_encode($extraction)]], JSON_THROW_ON_ERROR));
        $state = new LeadState;
        $policy = new Policy($draft);
        $extraction['_text'] = $client;
        $policy->applyExtraction($state, $extraction);
        $goal = $policy->decide($state, $extraction, true);
        self::assertSame('Английский', $state->slot('course'));
        self::assertSame('Продвинутый', $state->slot('level'));
        $answer = 'Записал курс и уровень, продолжаем.';
        $responderBody = app(DraftResponder::class)->body($draft, $state, $goal, $client);
        file_put_contents($dir.'/'.CanonicalJson::hash($responderBody).'.json', json_encode(['result' => ['content' => $answer]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->postJson('/seller/knowledge/sandbox/reply', ['release' => $draft->releaseId(), 'text' => $client])
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('answer', $answer);

        $invalidClient = 'Хочу на Английский, продвинутый.';
        $invalid = ['client_intent' => 'unclear', 'slot_updates' => ['course' => 'Английский', 'level' => 'Эксперт']];
        $invalidBody = $runtime->body($draft, 'extract', ['client' => $invalidClient, 'intents' => $draft->intentIds()]);
        file_put_contents($dir.'/'.CanonicalJson::hash($invalidBody).'.json', json_encode(['result' => ['content' => json_encode($invalid)]], JSON_THROW_ON_ERROR));
        $this->postJson('/seller/knowledge/sandbox/reply', ['release' => $draft->releaseId(), 'text' => $invalidClient])
            ->assertOk()->assertJsonPath('status', 'unavailable');
    }
}
