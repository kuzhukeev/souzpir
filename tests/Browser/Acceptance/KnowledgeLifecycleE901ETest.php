<?php

namespace Tests\Browser\Acceptance;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;
use Tests\Feature\Tenancy\TenancyTestCase;

final class KnowledgeLifecycleE901ETest extends TenancyTestCase
{
    public function test_fact_sandbox_publication_and_safe_rollback_are_brand_scoped(): void
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
        $draft = app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->createDraft($tenant, $data));
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => base_path('.local/runtime/e901e-empty')]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'e901e_topic', 'topic' => 'Тестовая тема'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/seller/knowledge/facts', ['version' => 2, 'group' => 'e901e_topic', 'text' => 'Первый проверенный факт'])->assertRedirect()->assertSessionHasNoErrors();
        $editor = $this->get('/seller/knowledge/facts')->assertOk()->inertiaProps('editor');
        $group = collect($editor['groups'])->firstWhere('key', 'e901e_topic');
        self::assertNotNull($group);
        $fact = $group['facts'][0]['id'];
        $first = $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $draft['id'], 'text' => 'Уточню: Воронеж'])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('state.slots.city', 'Воронеж')->json();
        $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $draft['id'], 'text' => 'Уточню: для себя'])->assertOk()->assertJsonPath('state.slots.city', 'Воронеж')->assertJsonPath('state.slots.purpose', 'для себя')->assertJsonCount(2, 'history');
        self::assertSame('ok', $first['inspector']['guard']['verdict']);
        $this->post('/seller/knowledge/'.$draft['id'].'/publish', ['version' => $editor['version']])->assertRedirect()->assertSessionHasNoErrors();
        $firstRelease = DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id');
        self::assertNotNull($firstRelease);
        $editor = $this->get('/seller/knowledge/facts')->assertOk()->inertiaProps('editor');
        $this->post('/seller/knowledge/facts/'.$fact, ['version' => $editor['version'], 'text' => 'Второй проверенный факт', 'status' => 'active', 'source' => 'manual'])->assertRedirect()->assertSessionHasNoErrors();
        $editor = $this->get('/seller/knowledge/facts')->assertOk()->inertiaProps('editor');
        $this->post('/seller/knowledge/'.$draft['id'].'/publish', ['version' => $editor['version']])->assertRedirect()->assertSessionHasNoErrors();
        $secondRelease = DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id');
        self::assertNotSame($firstRelease, $secondRelease);
        $onNewVersion = $this->postJson('/seller/knowledge/sandbox/reply', ['simulation' => true, 'release' => $secondRelease, 'text' => 'Уточню: Воронеж'])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('release', $secondRelease)->assertJsonPath('state.slots.city', 'Воронеж')->json();
        self::assertNotSame($first['checksum'], $onNewVersion['checksum']);
        $this->post('/seller/knowledge/'.$firstRelease.'/rollback')->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame($secondRelease, DB::table('brands')->where('id', $brand->id)->value('active_knowledge_release_id'));
        $rolledBack = $this->get('/seller/knowledge/facts')->assertOk()->inertiaProps('editor');
        $restored = collect($rolledBack['groups'])->firstWhere('key', 'e901e_topic');
        self::assertSame('Первый проверенный факт', $restored['facts'][0]['text']);
        $this->flushSession()->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/knowledge/'.$firstRelease.'/rollback')->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/'.$firstRelease.'/rollback')->assertForbidden();
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
