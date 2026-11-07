<?php

namespace Tests\Feature\Seller\Knowledge\Releases;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReleaseComparisonTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function emptyData(): array
    {
        return array_fill_keys(KnowledgeSnapshot::FILES, []);
    }

    public function test_empty_published_releases_compare_without_writes(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        [$first, $second] = app(TenantContextRunner::class)->run($context, function () use ($context): array {
            $store = app(KnowledgeRepository::class);
            $draft = $store->createDraft($context, $this->emptyData());
            $first = $store->publish($context, $draft['id'], 1)->releaseId();
            $store->saveDraft($context, $draft['id'], 2, $this->emptyData());
            $second = $store->publish($context, $draft['id'], 3)->releaseId();

            return [$first, $second];
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get("/seller/knowledge/releases/compare?from=$first&to=$second")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Seller/Knowledge/Releases/Compare')
                ->has('sections.facts.changes', 0)->has('sections.conditions.changes', 0)
                ->has('sections.topics.changes', 0)->has('sections.examples.changes', 0));
        $this->assertDatabaseCount('knowledge_releases', 3);
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'active_knowledge_release_id' => $second]);
    }

    public function test_changed_snapshots_show_four_sections_and_preserve_brand_isolation(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        [$first, $second, $draftId] = app(TenantContextRunner::class)->run($context, function () use ($context): array {
            $source = $this->emptyData();
            $source['facts'] = [['id' => 'company', 'facts' => ['Старый факт']]];
            $source['conditions'] = ['delivery' => ['days' => 2]];
            $source['intents'] = [['id' => 'ask_delivery', 'description' => 'Старая тема']];
            $source['fewshot'] = [['tag' => 'open', 'text' => 'Старый образец']];
            $store = app(KnowledgeRepository::class);
            $draft = $store->createDraft($context, $source);
            $first = $store->publish($context, $draft['id'], 1)->releaseId();
            $fact = DB::table('kn_facts as fact')->join('kn_fact_groups as grp', 'grp.id', '=', 'fact.group_id')
                ->where('grp.release_id', $draft['id'])->value('fact.id');
            $version = $store->setFact($context, $draft['id'], $fact, 2, 'Новый факт', 'active', 'manual');
            $changed = $store->forRelease($context, $draft['id'], true)->toArray();
            $changed['conditions']['delivery']['days'] = 3;
            $changed['intents'][0]['description'] = 'Новая тема';
            $changed['fewshot'][0]['text'] = 'Новый образец';
            $saved = $store->saveDraft($context, $draft['id'], $version, $changed);
            $second = $store->publish($context, $draft['id'], $saved['version'])->releaseId();

            return [$first, $second, $draft['id']];
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page
            ->where('counts.facts', 1)->where('counts.conditions', 1)->where('counts.intents', 1)
            ->where('counts.examples', 1)->where('counts.closed_topics', 0)->where('changeCount', 0));
        app(TenantContextRunner::class)->run($context, function () use ($context, $draftId): void {
            $factId = DB::table('kn_facts as fact')->join('kn_fact_groups as grp', 'grp.id', '=', 'fact.group_id')
                ->where('grp.release_id', $draftId)->value('fact.id');
            $version = DB::table('knowledge_releases')->where('id', $draftId)->value('version');
            app(KnowledgeRepository::class)->setFact($context, $draftId, $factId, $version, 'Ещё новый факт', 'active', 'manual');
        });
        $this->get('/seller/knowledge')->assertOk()->assertInertia(fn ($page) => $page->where('changeCount', 1));
        $this->get("/seller/knowledge/releases/compare?from=$first&to=$second")->assertOk()
            ->assertInertia(fn ($page) => $page->where('sections.facts.changes.0.after.facts.0', 'Новый факт')
                ->where('sections.conditions.changes.0.after.days', 3)
                ->where('sections.topics.changes.0.after.description', 'Новая тема')
                ->where('sections.examples.changes.0.after.text', 'Новый образец'));
        $this->get("/seller/knowledge/releases/compare?from=$first&to=$first")->assertSessionHasErrors('to');
        $this->get("/seller/knowledge/releases/compare?from=invalid&to=$second")->assertSessionHasErrors('from');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get("/seller/knowledge/releases/compare?from=$first&to=$second")->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get("/seller/knowledge/releases/compare?from=$first&to=$second")->assertOk();
        $this->get("/seller/knowledge/releases/compare?from=$first&to=$draftId")->assertForbidden();
    }
}
