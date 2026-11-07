<?php

namespace Tests\Feature\Seller\Knowledge\Conditions;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ApprovalsAndDeliveryE403FTest extends TenancyTestCase
{
    private function input(): array
    {
        return ['version' => 1, 'max_pct_unapproved' => 15,
            'deferred_payment' => ['available_to' => 'Постоянным клиентам от 3 заказов'],
            'delivery_by_city' => 'от 300 ₽', 'delivery_by_country' => 'от 500 ₽', 'delivery_free_from' => 'от 50 000 ₽',
            'approved' => ['min_order_rub' => false, 'offseason_min_order_rub' => false, 'reserve_days' => false, 'new_client_bonus_pct' => false,
                'min_order' => true, 'discounts' => true, 'payment' => false, 'delivery' => true, 'promotions' => false, 'other' => false]];
    }

    public function test_card_approvals_and_delivery_fields_are_saved_and_brand_isolated(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->createDraft($tenant, array_fill_keys(KnowledgeSnapshot::FILES, [])));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/conditions', $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/conditions')->assertOk()->assertInertia(fn ($page) => $page
            ->where('conditions.approvals.min_order', true)
            ->where('conditions.approvals.discounts', true)
            ->where('conditions.approvals.payment', false)
            ->where('conditions.deferred_payment.available_to', 'Постоянным клиентам от 3 заказов')
            ->where('conditions.delivery_by_city', 'от 300 ₽')
            ->where('conditions.delivery_by_country', 'от 500 ₽')
            ->where('conditions.delivery_free_from', 'от 50 000 ₽'));

        // "Утвердить все" merges with existing approvals without touching unrelated keys.
        $all = $this->input();
        $all['version'] = 2;
        $all['approved'] = ['min_order_rub' => false, 'offseason_min_order_rub' => false, 'reserve_days' => false, 'new_client_bonus_pct' => false,
            'min_order' => true, 'discounts' => true, 'payment' => true, 'delivery' => true, 'promotions' => true, 'other' => true];
        $this->post('/seller/knowledge/conditions', $all)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/conditions')->assertOk()->assertInertia(fn ($page) => $page
            ->where('conditions.approvals.payment', true)
            ->where('conditions.approvals.promotions', true)
            ->where('conditions.approvals.other', true));

        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/conditions')->assertOk()->assertInertia(fn ($page) => $page->where('draft', null));
    }
}
