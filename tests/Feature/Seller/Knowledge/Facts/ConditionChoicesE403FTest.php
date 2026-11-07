<?php

namespace Tests\Feature\Seller\Knowledge\Facts;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ConditionChoicesE403FTest extends TenancyTestCase
{
    private array $factHeaders = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Seller/Knowledge/Facts/Show', 'X-Inertia-Partial-Data' => 'factData'];

    public function test_new_conditions_can_be_selected_saved_resolved_and_linked(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['conditions'] = ['min_order_quantity' => 12, 'prepayment_pct' => 30, 'availability' => 'Уточнить', 'other_conditions' => 'По запросу'];
            $data['facts'] = [['id' => 'order', 'topic' => 'Заказ', 'facts' => ['Исходный факт']]];

            return app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $draft['id'])->value('f.id');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $options = $this->get('/seller/knowledge/facts/'.$fact, $this->factHeaders)->assertOk()->json('props.factData.conditionOptions');
        $this->assertEqualsCanonicalizing(['min_order_quantity', 'prepayment_pct', 'availability', 'other_conditions'], array_column($options, 'key'));
        $text = 'Заказ {cond:min_order_quantity}, предоплата {cond:prepayment_pct}, наличие {cond:availability}, прочее {cond:other_conditions}';
        $this->post('/seller/knowledge/facts/'.$fact, ['version' => 1, 'text' => $text, 'status' => 'active', 'source' => 'manual', 'group' => 'order'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/facts/'.$fact, $this->factHeaders)->assertOk()->assertJsonPath('props.factData.fact.text', $text);
        $this->get('/seller/knowledge/conditions')->assertOk()->assertInertia(fn ($page) => $page->has('factLinks.min_order_quantity', 1)->where('factLinks.min_order_quantity.0.id', $fact)->has('factLinks.prepayment_pct', 1)->has('factLinks.availability', 1)->has('factLinks.other_conditions', 1));
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $draft, $text): void {
            $snapshot = app(KnowledgeRepository::class)->forRelease($tenant, $draft['id'], true);
            $this->assertSame('Заказ 12, предоплата 30, наличие Уточнить, прочее По запросу', $snapshot->resolvePlaceholders($text));
        });
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/facts/'.$fact)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/knowledge/facts/'.$fact)->assertForbidden();
    }
}
