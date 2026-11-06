<?php

namespace Tests\Feature\Seller\Knowledge\Facts;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FactComparisonE402CTest extends TenancyTestCase
{
    public function test_comparison_uses_published_fact_of_current_brand_only(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'delivery'])->assertRedirect();
        $this->post('/seller/knowledge/facts', ['version' => 2, 'group' => 'delivery', 'text' => 'Before'])->assertRedirect();
        $editor = $this->get('/seller/knowledge/facts')->inertiaProps('editor');
        $id = $editor['groups'][0]['facts'][0]['id'];
        $this->get('/seller/knowledge/facts/'.$id)->assertOk()->assertInertia(fn ($page) => $page->where('publishedText', null));
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $editor): void {
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $editor['id'], true)->toArray();
            $data['persona'] = ['role' => 'Assistant', 'company' => 'Company', 'tone' => [], 'length' => ['target_words' => [10, 30], 'max_words' => 90, 'max_sentences' => 3], 'hard_bans' => [], 'stop_phrases' => []];
            $repo->saveDraft($tenant, $editor['id'], 3, $data);
        });
        $this->post('/seller/knowledge/'.$editor['id'].'/publish', ['version' => 4])->assertRedirect();
        $editor = $this->get('/seller/knowledge/facts')->inertiaProps('editor');
        $id = $editor['groups'][0]['facts'][0]['id'];
        $this->post('/seller/knowledge/facts/'.$id, ['version' => $editor['version'], 'text' => 'After', 'status' => 'active', 'source' => 'manual'])->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/facts/'.$id)->assertOk()->assertInertia(fn ($page) => $page->where('publishedText', 'Before')->where('fact.text', 'After'));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/knowledge/facts/'.$id)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/knowledge/facts/'.$id)->assertNotFound();
    }

    public function test_fact_can_move_to_another_draft_topic_atomically(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'delivery', 'topic' => 'Delivery'])->assertRedirect();
        $this->post('/seller/knowledge/facts', ['version' => 2, 'group' => 'delivery', 'text' => 'Before'])->assertRedirect();
        $editor = $this->get('/seller/knowledge/facts')->inertiaProps('editor');
        $id = $editor['groups'][0]['facts'][0]['id'];
        $this->post('/seller/knowledge/facts/group', ['version' => 3, 'key' => 'payment', 'topic' => 'Payment'])->assertRedirect();
        $this->get('/seller/knowledge/facts/'.$id)->assertOk()->assertInertia(fn ($page) => $page->has('topics', 2)->where('topics.1.label', 'Payment'));
        $input = ['version' => 4, 'text' => 'After', 'status' => 'active', 'source' => 'manual', 'group' => 'missing'];
        $this->post('/seller/knowledge/facts/'.$id, $input)->assertNotFound();
        $this->get('/seller/knowledge/facts/'.$id)->assertInertia(fn ($page) => $page->where('version', 4)->where('fact.text', 'Before')->where('fact.group_key', 'delivery'));
        $input['group'] = 'payment';
        $this->post('/seller/knowledge/facts/'.$id, $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/facts/'.$id)->assertInertia(fn ($page) => $page->where('version', 6)->where('fact.text', 'After')->where('fact.group_key', 'payment'));
        $this->post('/seller/knowledge/facts/'.$id, $input)->assertStatus(409);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/facts/'.$id, $input)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/knowledge/facts/'.$id, $input)->assertNotFound();
    }

    public function test_condition_placeholder_is_saved_and_resolved_in_snapshot(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'delivery'])->assertRedirect();
        $this->post('/seller/knowledge/facts', ['version' => 2, 'group' => 'delivery', 'text' => 'Minimum'])->assertRedirect();
        $editor = $this->get('/seller/knowledge/facts')->inertiaProps('editor');
        $id = $editor['groups'][0]['facts'][0]['id'];
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $editor): void {
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $editor['id'], true)->toArray();
            $data['conditions']['min_order_rub'] = 5000;
            $data['intents'] = [['id' => 'ask_delivery', 'description' => 'Delivery question', 'facts' => ['delivery']]];
            $repo->saveDraft($tenant, $editor['id'], 3, $data);
        });
        $this->get('/seller/knowledge/facts/'.$id)->assertOk()->assertInertia(fn ($page) => $page->where('conditionOptions.0.key', 'min_order_rub')->where('intentLinks.0.id', 'ask_delivery')->where('intentLinks.0.groups.0', 'delivery'));
        $this->post('/seller/knowledge/facts/'.$id, ['version' => 4, 'text' => 'Minimum {cond:min_order_rub}', 'status' => 'active', 'source' => 'manual'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/facts/'.$id)->assertInertia(fn ($page) => $page->where('fact.text', 'Minimum {cond:min_order_rub}'));
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $editor): void {
            $repository = app(KnowledgeRepository::class);
            $snapshot = $repository->forRelease($tenant, $editor['id'], true);
            self::assertSame('Minimum 5 тыс. руб.', $snapshot->fact('delivery')['facts'][0]);
            $data = $snapshot->toArray();
            $data['persona'] = ['role' => 'Assistant', 'company' => 'Company', 'tone' => [], 'length' => ['target_words' => [10, 30], 'max_words' => 90, 'max_sentences' => 3], 'hard_bans' => [], 'stop_phrases' => []];
            $repository->saveDraft($tenant, $editor['id'], 5, $data);
            $repository->publish($tenant, $editor['id'], 6);
            self::assertSame('Minimum 5 тыс. руб.', $repository->published($tenant)->fact('delivery')['facts'][0]);
        });
    }
}
