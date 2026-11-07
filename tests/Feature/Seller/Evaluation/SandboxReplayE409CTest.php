<?php

namespace Tests\Feature\Seller\Evaluation;

use App\Seller\Evaluation\Jobs\SimulateFakeJob;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Sandbox\SandboxRuntime;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SandboxReplayE409CTest extends TenancyTestCase
{
    public function test_published_sandbox_and_saved_replay_keep_turns_and_isolated_fake_handoff(): void
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
        $published = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $data) {
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);

            return $repo->publish($tenant, $draft['id'], 1)->releaseId();
        });
        config(['runtime.ai_driver' => 'fake', 'features.E3-06D.defaults.runtime_enabled' => true,
            'features.E0-05.defaults.fixture_directory' => storage_path('framework/testing/empty-e409c')]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $inputs = ['Уточню: Воронеж', 'Уточню: для себя', 'Мне нужен человек, не бот.'];
        foreach ($inputs as $text) {
            $result = $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $published, 'text' => $text])->assertOk()->assertJsonPath('status', 'completed')->json();
        }
        self::assertSame('Воронеж', $result['state']['slots']['city']);
        self::assertSame('для себя', $result['state']['slots']['purpose']);
        self::assertSame([['name' => 'escalate', 'status' => 'fake_completed']], $result['inspector']['executed_tools']);
        self::assertCount(3, $result['history']);
        $transcript = implode("\n\n", array_map(fn ($turn) => 'Клиент: '.$turn['question']."\nБот: ".$turn['answer'], $result['history']));
        $this->post('/seller/quality/scenarios', ['name' => 'Replay E409C', 'type' => 'edge', 'behavior' => $transcript, 'first_message' => $inputs[0], 'max_turns' => 3, 'expect' => []])->assertRedirect()->assertSessionHasNoErrors();
        $key = DB::table('seller_personas')->where('brand_id', $brand->id)->where('name', 'Replay E409C')->value('key');
        SimulateFakeJob::dispatchSync($org->id, $brand->id, $key, [[], [], []]);
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->sole();
        $turns = json_decode($run->turns, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($inputs, array_column($turns, 'client'));
        self::assertIsString($turns[0]['meta']['goal']);
        self::assertSame($result['history'][1]['answer'], $turns[1]['agent']);
        self::assertSame([['name' => 'escalate', 'status' => 'fake_completed']], $turns[2]['meta']['tool_results']);
        self::assertSame('Воронеж', $turns[0]['meta']['slot_updates']['city']);
        self::assertSame('для себя', $turns[1]['meta']['slot_updates']['purpose']);
        self::assertSame($published, json_decode($run->metrics, true)['knowledge_release_id']);
        $draftId = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->value('id');
        $this->post('/seller/knowledge/'.$draftId.'/scenarios')->assertRedirect()->assertSessionHasNoErrors();
        $draftRun = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->where('id', '!=', $run->id)->sole();
        $draftTurns = json_decode($draftRun->turns, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($inputs, array_column($draftTurns, 'client'));
        self::assertContains(['name' => 'escalate', 'status' => 'fake_completed'], $draftTurns[2]['meta']['tool_results']);
        $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $published, 'start_new' => true, 'text' => 'Уточню: Воронеж'])->assertOk();
        $beforeRepeat = $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $published, 'start_new' => true, 'text' => 'Уточню: Воронеж'])->assertOk()->json();
        $repeat = $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $published, 'text' => 'Повторите'])->assertOk()->json();
        self::assertSame($beforeRepeat['history'][0]['answer'], $repeat['history'][1]['answer']);
        self::assertSame('history', $repeat['inspector']['response_source']);
        self::assertSame('ok', $repeat['inspector']['guard']['verdict']);
        self::assertSame([], $repeat['inspector']['executed_tools']);
        $blockedRepeat = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $published, $beforeRepeat): array {
            $snapshot = app(KnowledgeRepository::class)->forRelease($tenant, $published, true);

            return app(SandboxRuntime::class)->turn(Request::create('/'), $snapshot, 'Повтори', $beforeRepeat['state'], [['question' => 'Привет', 'answer' => 'Кто? Где?']], false, null);
        });
        self::assertNotSame('Кто? Где?', $blockedRepeat['answer']);
        self::assertNotSame('ok', $blockedRepeat['inspector']['guard']['verdict']);
        self::assertSame('guard_fallback', $blockedRepeat['inspector']['response_source']);

        $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $published, 'start_new' => true, 'text' => 'Уточню: Воронеж'])->assertOk();
        $requisites = $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $published, 'text' => 'Пришлите реквизиты и договор.'])->assertOk()->json();
        self::assertSame([['name' => 'send_requisites', 'status' => 'fake_completed']], $requisites['inspector']['executed_tools']);
        self::assertSame('ok', $requisites['inspector']['guard']['verdict']);
        $large = $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $published, 'start_new' => true, 'text' => 'Планирую заказ на 700 тысяч.'])->assertOk()->json();
        self::assertSame('700 тысяч', $large['state']['slots']['budget_range']);
        self::assertSame('big_order', $large['state']['signals']['notify_manager']);
        self::assertSame([['name' => 'notify_manager', 'status' => 'fake_completed']], $large['inspector']['executed_tools']);
        self::assertStringContainsString('уведомление менеджеру передано', $large['history'][0]['answer']);
        self::assertDatabaseCount('conversations', 0);
        self::assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $published, 'text' => $inputs[0]])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $published, 'text' => $inputs[0]])->assertNotFound();
    }
}
