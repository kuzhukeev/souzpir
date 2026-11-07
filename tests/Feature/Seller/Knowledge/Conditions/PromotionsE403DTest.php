<?php

namespace Tests\Feature\Seller\Knowledge\Conditions;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PromotionsE403DTest extends TenancyTestCase
{
    public function test_promotions_are_validated_saved_to_draft_and_brand_isolated(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['conditions'] = ['returns' => false];
            app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        $input = ['version' => 1, 'max_pct_unapproved' => 30, 'approved' => ['min_order_rub' => false, 'offseason_min_order_rub' => false, 'reserve_days' => false, 'new_client_bonus_pct' => false],
            'promotions' => [['name' => 'Осенняя', 'text' => 'Скидка на товар', 'from' => '2026-10-01', 'until' => '2026-10-31']]];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $bad = $input;
        $bad['promotions'][0]['until'] = '2026-09-01';
        $this->post('/seller/knowledge/conditions', $bad)->assertSessionHasErrors('promotions.0.until');
        $bad = $input;
        $bad['promotions'][0]['name'] = '';
        $this->post('/seller/knowledge/conditions', $bad)->assertSessionHasErrors('promotions.0.name');
        $this->post('/seller/knowledge/conditions', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/conditions')->assertOk()->assertInertia(fn ($page) => $page
            ->where('conditions.promotions.0.name', 'Осенняя')->where('conditions.promotions.0.from', '2026-10-01')
            ->where('conditions.promotions.0.until', '2026-10-31')->where('conditions.returns', false));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/conditions')->assertInertia(fn ($page) => $page->where('draft', null));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/conditions', $input)->assertForbidden();
    }
}
