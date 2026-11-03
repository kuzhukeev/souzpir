<?php

namespace Tests\Feature\Seller\Knowledge\Conditions;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ConditionsMethodsTest extends TenancyTestCase
{
    public function test_methods_are_validated_and_saved_in_the_current_brand_snapshot(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['conditions'] = ['payment_methods' => ['По счёту']];

            return app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        $input = ['version' => 1, 'max_pct_unapproved' => 30, 'approved' => ['min_order_rub' => false, 'offseason_min_order_rub' => false, 'reserve_days' => false, 'new_client_bonus_pct' => false],
            'payment_options' => [['name' => 'Карта', 'description' => 'При получении', 'enabled' => true], ['name' => 'Наличные', 'description' => '', 'enabled' => false]],
            'delivery_methods' => [['name' => 'Курьер', 'time' => 'Два дня', 'cost' => 'По тарифу', 'zones' => 'Москва']]];
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $bad = $input;
        $bad['payment_options'][0]['name'] = '';
        $this->post('/seller/knowledge/conditions', $bad)->assertSessionHasErrors('payment_options.0.name');
        $bad = $input;
        $bad['delivery_methods'][0]['zones'] = str_repeat('x', 1001);
        $this->post('/seller/knowledge/conditions', $bad)->assertSessionHasErrors('delivery_methods.0.zones');
        $this->post('/seller/knowledge/conditions', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/conditions')->assertOk()->assertInertia(fn ($page) => $page->where('conditions.payment_methods', ['Карта'])->where('conditions.payment_options.1.enabled', false)->where('conditions.delivery_methods.0.zones', 'Москва'));
        $this->post('/seller/knowledge/'.$draft['id'].'/publish', ['version' => 2])->assertRedirect();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $conditions = app(KnowledgeRepository::class)->published($tenant)->conditions();
            $this->assertSame('При получении', $conditions['payment_options'][0]['description']);
            $this->assertSame('По тарифу', $conditions['delivery_methods'][0]['cost']);
        });
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/conditions')->assertInertia(fn ($page) => $page->where('draft', null));
        $this->post('/seller/knowledge/conditions', $input)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/knowledge/conditions', $input)->assertForbidden();
    }
}
