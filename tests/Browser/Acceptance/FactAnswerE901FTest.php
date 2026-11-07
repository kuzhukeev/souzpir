<?php

namespace Tests\Browser\Acceptance;

use App\Ai\CanonicalJson;
use App\ProtectedOperations\DraftSimulation\FixtureRuntime;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FactAnswerE901FTest extends TenancyTestCase
{
    public function test_changed_fact_changes_sandbox_answer_without_live_delivery(): void
    {
        Http::preventStrayRequests();
        config(['runtime.ai_driver' => 'fake', 'features.E0-04.defaults.external_delivery' => false, 'features.E5-01H.defaults.live_enabled' => false]);
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
        foreach ($data['facts'] as &$group) {
            if ($group['id'] === 'min_order') {
                $group['facts'] = ['Заказ обсуждается с менеджером.'];
            }
        }
        unset($group);
        $draft = app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->createDraft($tenant, $data));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $dir = storage_path('framework/testing/e901f-'.Str::ulid());
        mkdir($dir, 0777, true);
        config(['features.E0-05.defaults.fixture_directory' => $dir]);
        $client = 'Какой минимальный заказ?';
        $ask = function (string $expected) use ($tenant, $draft, $dir, $client): void {
            $snapshot = app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->forRelease($tenant, $draft['id'], true));
            $body = app(FixtureRuntime::class)->body($snapshot, 'extract', ['client' => $client, 'intents' => $snapshot->intentIds()]);
            file_put_contents($dir.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => ['content' => json_encode(['client_intent' => 'ask_min_order', 'slot_updates' => []])]], JSON_THROW_ON_ERROR));
            $result = $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'start_new' => true, 'release' => $draft['id'], 'text' => $client])->assertOk()->assertJsonPath('status', 'completed')->json();
            self::assertStringContainsString($expected, $result['history'][0]['answer']);
        };
        $ask('Заказ обсуждается с менеджером.');
        $editor = $this->get('/seller/knowledge/facts')->assertOk()->inertiaProps('editor');
        $group = collect($editor['groups'])->firstWhere('key', 'min_order');
        self::assertNotNull($group);
        $this->post('/seller/knowledge/facts/'.$group['facts'][0]['id'], ['version' => $editor['version'], 'text' => 'Условия заказа уточняем заранее.', 'status' => 'active', 'source' => 'manual'])->assertRedirect()->assertSessionHasNoErrors();
        $ask('Условия заказа уточняем заранее.');
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
