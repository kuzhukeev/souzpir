<?php

namespace Tests\Feature\Seller\Knowledge\Facts;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FactChangesE410Test extends TenancyTestCase
{
    public function test_fact_statuses_compare_only_with_published_version_of_brand(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'delivery'])->assertRedirect();
        $this->post('/seller/knowledge/facts', ['version' => 2, 'group' => 'delivery', 'text' => 'Исходный факт'])->assertRedirect();
        $editor = $this->get('/seller/knowledge/facts')->assertOk()->inertiaProps('editor');
        self::assertSame('new', $editor['groups'][0]['facts'][0]['change_status']);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $editor): void {
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $editor['id'], true)->toArray();
            $data['persona'] = ['role' => 'Консультант', 'company' => 'Компания', 'tone' => [], 'length' => ['target_words' => [10, 30], 'max_words' => 90, 'max_sentences' => 3], 'hard_bans' => [], 'stop_phrases' => []];
            $repo->saveDraft($tenant, $editor['id'], 3, $data);
        });
        $this->post('/seller/knowledge/'.$editor['id'].'/publish', ['version' => 4])->assertRedirect();
        $editor = $this->get('/seller/knowledge/facts')->assertOk()->inertiaProps('editor');
        self::assertNull($editor['groups'][0]['facts'][0]['change_status']);
        $id = $editor['groups'][0]['facts'][0]['id'];
        $this->post('/seller/knowledge/facts/'.$id, ['version' => $editor['version'], 'text' => 'Изменённый факт', 'status' => 'active', 'source' => 'manual'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/seller/knowledge/facts', ['version' => $editor['version'] + 1, 'group' => 'delivery', 'text' => 'Новый факт'])->assertRedirect()->assertSessionHasNoErrors();
        $editor = $this->get('/seller/knowledge/facts')->assertOk()->inertiaProps('editor');
        self::assertSame(['changed', 'new'], array_column($editor['groups'][0]['facts'], 'change_status'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/facts')->assertOk()->assertDontSee('Изменённый факт');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/knowledge/facts')->assertForbidden();
    }
}
