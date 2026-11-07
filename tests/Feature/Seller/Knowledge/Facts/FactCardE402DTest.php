<?php

namespace Tests\Feature\Seller\Knowledge\Facts;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FactCardE402DTest extends TenancyTestCase
{
    public function test_group_rules_save_and_reopen_without_affecting_another_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'delivery'])->assertRedirect();
        $this->post('/seller/knowledge/facts', ['version' => 2, 'group' => 'delivery', 'text' => 'Before'])->assertRedirect();
        $editor = $this->get('/seller/knowledge/facts')->inertiaProps('editor');
        $id = $editor['groups'][0]['facts'][0]['id'];
        $this->get('/seller/knowledge/facts/'.$id)->assertOk()->assertInertia(fn ($page) => $page->where('example', null));
        $input = ['version' => 3, 'text' => 'After', 'status' => 'active', 'source' => 'manual', 'group' => 'delivery', 'bridge_hint' => 'Ask city', 'must_not' => "No promise\nNo date"];
        $this->post('/seller/knowledge/facts/'.$id, $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/facts/'.$id)->assertInertia(fn ($page) => $page->where('fact.text', 'After')->where('version', 5)->where('group.bridge_hint', 'Ask city')->where('group.must_not', ['No promise', 'No date']));
        Http::preventStrayRequests();
        $this->post('/seller/knowledge/facts/'.$id.'/example', ['version' => 5])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/facts/'.$id)->assertInertia(fn ($page) => $page->where('example.answer', 'After')->where('history.0.before_text', 'Before')->where('history.0.after_text', 'After')->where('version', 6));
        Http::assertNothingSent();
        $this->post('/seller/knowledge/facts/'.$id, $input)->assertStatus(409);
        $this->post('/seller/knowledge/facts/'.$id, [...$input, 'version' => 6, 'text' => 'Should not persist', 'intent_ids' => ['unknown']])->assertSessionHasErrors('intent_ids');
        $this->post('/seller/knowledge/facts/'.$id, [...$input, 'version' => 6, 'text' => ''])->assertSessionHasErrors('text');
        $this->get('/seller/knowledge/facts/'.$id)->assertInertia(fn ($page) => $page->where('fact.text', 'After')->where('version', 6));

        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/facts/'.$id, [...$input, 'version' => 5])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/knowledge/facts/'.$id, [...$input, 'version' => 5])->assertNotFound();
    }

    public function test_individual_intent_selection_preserves_other_facts_and_published_draft(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'delivery'])->assertRedirect();
        $this->post('/seller/knowledge/facts', ['version' => 2, 'group' => 'delivery', 'text' => 'First'])->assertRedirect();
        $this->post('/seller/knowledge/facts', ['version' => 3, 'group' => 'delivery', 'text' => 'Second'])->assertRedirect();
        $editor = $this->get('/seller/knowledge/facts')->inertiaProps('editor');
        $id = $editor['groups'][0]['facts'][0]['id'];
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $editor): void {
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $editor['id'], true)->toArray();
            $data['intents'] = [['id' => 'delivery', 'facts' => ['delivery']], ['id' => 'new', 'facts' => []]];
            $data['persona'] = ['role' => 'Assistant', 'company' => 'Company', 'tone' => [], 'length' => ['target_words' => [10, 30], 'max_words' => 90, 'max_sentences' => 3], 'hard_bans' => [], 'stop_phrases' => []];
            $repo->saveDraft($tenant, $editor['id'], 4, $data);
            self::assertSame(['First', 'Second'], $repo->forRelease($tenant, $editor['id'], true)->factsForIntent('delivery')[0]['facts']);
        });
        $this->post('/seller/knowledge/facts/'.$id, ['version' => 5, 'text' => 'First changed', 'status' => 'active', 'source' => 'manual', 'group' => 'delivery', 'intent_ids' => ['new']])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/facts/'.$id)->assertInertia(fn ($page) => $page->where('intentLinks.0.selected', false)->where('intentLinks.1.selected', true));
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $editor): void {
            $repo = app(KnowledgeRepository::class);
            $snapshot = $repo->forRelease($tenant, $editor['id'], true);
            self::assertSame(['Second'], $snapshot->factsForIntent('delivery')[0]['facts']);
            self::assertSame(['First changed'], $snapshot->factsForIntent('new')[0]['facts']);
            $published = $repo->publish($tenant, $editor['id'], 7);
            self::assertSame(['Second'], $published->factsForIntent('delivery')[0]['facts']);
            self::assertSame(['First changed'], $repo->published($tenant)->factsForIntent('new')[0]['facts']);
            DB::table('knowledge_releases')->where('id', $editor['id'])->update(['status' => 'building']);
            $copy = $repo->createDraft($tenant, $repo->published($tenant)->toArray());
            $copied = $repo->forRelease($tenant, $copy['id'], true);
            self::assertSame(['Second'], $copied->factsForIntent('delivery')[0]['facts']);
            self::assertSame(['First changed'], $copied->factsForIntent('new')[0]['facts']);
        });
    }
}
