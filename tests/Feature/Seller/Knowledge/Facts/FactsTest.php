<?php

namespace Tests\Feature\Seller\Knowledge\Facts;

use App\Ai\Protected\ResponderProjection;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FactsTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_facts_groups_publish_and_responder_prompt(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'delivery', 'topic' => 'Доставка', 'bridge_hint' => 'Уточнить город', 'must_not' => 'Не обещать срок'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/seller/knowledge/facts', ['version' => 2, 'group' => 'delivery', 'text' => 'Доставка по согласованию'])->assertRedirect()->assertSessionHasNoErrors();
        $draft = DB::table('knowledge_releases')->where('brand_id', $brand->id)->where('status', 'draft')->first();
        $fact = DB::table('kn_facts')->join('kn_fact_groups', 'kn_facts.group_id', '=', 'kn_fact_groups.id')->where('kn_fact_groups.release_id', $draft->id)->value('kn_facts.id');
        $this->post("/seller/knowledge/facts/{$fact}", ['version' => 3, 'text' => 'Доставка по России', 'status' => 'active', 'source' => 'manual'])->assertRedirect();
        $this->post('/seller/knowledge/facts/group/delete', ['version' => 4, 'key' => 'delivery'])->assertSessionHasErrors('key');
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $draft): void {
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            $data['persona'] = ['role' => 'консультант', 'company' => 'Компания', 'tone' => [], 'length' => ['target_words' => [10, 30], 'max_words' => 90, 'max_sentences' => 3], 'hard_bans' => [], 'stop_phrases' => []];
            $repo->saveDraft($tenant, $draft->id, 4, $data);
        });
        $this->post("/seller/knowledge/{$draft->id}/publish", ['version' => 5])->assertRedirect();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $snapshot = app(KnowledgeRepository::class)->published($tenant);
            $group = $snapshot->fact('delivery');
            $this->assertSame(['Доставка по России'], $group['facts']);
            $this->assertSame(['Не обещать срок'], $group['must_not']);
            $prompt = app(ResponderProjection::class)->render($snapshot, new LeadState, new Goal('answer_only', facts: [$group]), 'Продавец');
            $this->assertStringContainsString('Доставка по России', $prompt);
        });
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/facts')->assertOk()->assertInertia(fn ($page) => $page->where('editor', null));
        $this->post("/seller/knowledge/facts/{$fact}/delete", ['version' => 5])->assertNotFound();
    }

    public function test_delete_fact_then_group(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'x'])->assertRedirect();
        $this->post('/seller/knowledge/facts', ['version' => 2, 'group' => 'x', 'text' => 'Тест'])->assertRedirect();
        $fact = DB::table('kn_facts')->value('id');
        $this->post("/seller/knowledge/facts/{$fact}/delete", ['version' => 3])->assertRedirect();
        $this->post('/seller/knowledge/facts/group/delete', ['version' => 4, 'key' => 'x'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('kn_facts', 0);
        $this->assertDatabaseCount('kn_fact_groups', 0);
    }

    public function test_errors_stale_version_and_viewer_access(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'BAD'])->assertSessionHasErrors('key');
        $this->post('/seller/knowledge/facts', ['version' => 1, 'group' => 'x', 'text' => ''])->assertSessionHasErrors('text');
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'x'])->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'y'])->assertStatus(409);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/facts')->assertForbidden();
        $this->post('/seller/knowledge/facts/group', ['version' => 2, 'key' => 'y'])->assertForbidden();
    }

    public function test_fact_card_history_bulk_actions_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'delivery'])->assertRedirect();
        $this->post('/seller/knowledge/facts', ['version' => 2, 'group' => 'delivery', 'text' => 'Бесплатная доставка'])->assertRedirect();
        $fact = DB::table('kn_facts')->first();

        $this->get('/seller/knowledge/facts')->assertOk()->assertInertia(fn ($page) => $page->where('editor.groups.0.facts.0.text', 'Бесплатная доставка'));
        $this->get("/seller/knowledge/facts/{$fact->id}")->assertOk()->assertInertia(fn ($page) => $page->where('fact.text', 'Бесплатная доставка')->has('history'));
        $this->post('/seller/knowledge/facts/bulk', ['version' => 3, 'action' => 'deactivate', 'ids' => [$fact->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('inactive', DB::table('kn_facts')->where('id', $fact->id)->value('status'));
        $this->get("/seller/knowledge/facts/{$fact->id}")->assertInertia(fn ($page) => $page->where('fact.status', 'inactive')->has('history.0'));

        $this->post('/seller/knowledge/facts/bulk', ['version' => 4, 'action' => 'bogus', 'ids' => [$fact->id]])->assertSessionHasErrors('action');
        $this->post('/seller/knowledge/facts/bulk', ['version' => 4, 'action' => 'delete', 'ids' => []])->assertSessionHasErrors('ids');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get("/seller/knowledge/facts/{$fact->id}")->assertForbidden();
        $this->post('/seller/knowledge/facts/bulk', ['version' => 4, 'action' => 'delete', 'ids' => [$fact->id]])->assertForbidden();

        $this->actingAs($owner)->withSession(['brand_id' => $other->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->get("/seller/knowledge/facts/{$fact->id}")->assertNotFound();
        $this->post('/seller/knowledge/facts/bulk', ['version' => 1, 'action' => 'delete', 'ids' => [$fact->id]])->assertNotFound();
        $this->assertDatabaseHas('kn_facts', ['id' => $fact->id, 'status' => 'inactive']);
    }

    public function test_bulk_move_preserves_facts_and_checks_destination_and_version(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'source', 'topic' => 'Доставка', 'bridge_hint' => 'Уточнить город', 'must_not' => 'Не обещать срок'])->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 2, 'key' => 'target'])->assertRedirect();
        $this->post('/seller/knowledge/facts', ['version' => 3, 'group' => 'source', 'text' => 'Первый'])->assertRedirect();
        $this->post('/seller/knowledge/facts', ['version' => 4, 'group' => 'source', 'text' => 'Второй'])->assertRedirect();
        $ids = DB::table('kn_facts')->orderBy('position')->pluck('id')->all();
        $this->get('/seller/knowledge/facts/'.$ids[0])->assertOk()->assertInertia(fn ($page) => $page->where('group.topic', 'Доставка')->where('group.bridge_hint', 'Уточнить город')->where('group.must_not.0', 'Не обещать срок'));
        $this->post('/seller/knowledge/facts/bulk', ['version' => 5, 'action' => 'move', 'ids' => $ids])->assertSessionHasErrors('group');
        $this->post('/seller/knowledge/facts/bulk', ['version' => 5, 'action' => 'move', 'group' => 'missing', 'ids' => $ids])->assertNotFound();
        $this->post('/seller/knowledge/facts/bulk', ['version' => 4, 'action' => 'move', 'group' => 'target', 'ids' => $ids])->assertStatus(409);
        $this->post('/seller/knowledge/facts/bulk', ['version' => 5, 'action' => 'move', 'group' => 'target', 'ids' => $ids])->assertRedirect()->assertSessionHasNoErrors();
        $target = DB::table('kn_fact_groups')->where('key', 'target')->value('id');
        $this->assertSame($ids, DB::table('kn_facts')->where('group_id', $target)->orderBy('position')->pluck('id')->all());
        $this->get('/seller/knowledge/facts/'.$ids[0])->assertOk()->assertInertia(fn ($page) => $page->where('fact.group_key', 'target')->has('history'));
    }
}
