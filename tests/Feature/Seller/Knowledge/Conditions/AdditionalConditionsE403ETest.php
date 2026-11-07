<?php

namespace Tests\Feature\Seller\Knowledge\Conditions;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AdditionalConditionsE403ETest extends TenancyTestCase
{
    public function test_fields_and_explicit_fact_references_are_brand_scoped(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['conditions'] = ['approved_by' => '', 'prepayment' => 'full', 'deferred_payment' => ['new_clients' => false, 'regular_clients' => 'by_manager']];
            $data['facts'] = [['id' => 'orders', 'topic' => 'Заказ', 'facts' => ['Минимум {cond:min_order_quantity}', 'Несвязанный факт', 'Неизвестно {cond:missing}', 'Отключённый {cond:min_order_quantity}']]];

            return app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        DB::table('kn_facts')->where('text', 'Отключённый {cond:min_order_quantity}')->update(['status' => 'inactive']);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = ['version' => 1, 'min_order_quantity' => 12, 'prepayment_pct' => 30, 'availability' => 'Уточнять по складу', 'other_conditions' => 'Условия возврата по запросу', 'deferred_payment' => ['new_clients' => 'no', 'regular_clients' => 'by_manager'], 'max_pct_unapproved' => 30, 'approved' => ['min_order_rub' => false, 'offseason_min_order_rub' => false, 'reserve_days' => false, 'new_client_bonus_pct' => false]];
        $bad = $input;
        $bad['prepayment_pct'] = 101;
        $this->post('/seller/knowledge/conditions', $bad)->assertSessionHasErrors('prepayment_pct');
        $bad = $input;
        $bad['min_order_quantity'] = -1;
        $this->post('/seller/knowledge/conditions', $bad)->assertSessionHasErrors('min_order_quantity');
        $this->post('/seller/knowledge/conditions', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/conditions')->assertOk()->assertInertia(fn ($page) => $page
            ->where('conditions.min_order_quantity', 12)->where('conditions.prepayment_pct', 30)->where('conditions.prepayment', 'full')
            ->where('conditions.deferred_payment.new_clients', false)->where('conditions.deferred_payment.regular_clients', 'by_manager')
            ->where('conditions.availability', 'Уточнять по складу')->where('conditions.other_conditions', 'Условия возврата по запросу')
            ->has('factLinks.min_order_quantity', 1)->where('factLinks.min_order_quantity.0.text', 'Минимум {cond:min_order_quantity}')->missing('factLinks.missing'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/conditions')->assertOk()->assertInertia(fn ($page) => $page->where('draft', null)->where('factLinks', []));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/conditions', $input)->assertForbidden();
    }
}
