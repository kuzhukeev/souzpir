<?php

namespace Tests\Feature\Seller\Knowledge\Conditions;

use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ConditionsTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function input(): array
    {
        return ['version' => 1, 'min_order_rub' => 10000, 'offseason_min_order_rub' => null, 'reserve_days' => 7, 'new_client_bonus_pct' => 5, 'max_pct_unapproved' => 15,
            'approved' => ['min_order_rub' => true, 'offseason_min_order_rub' => false, 'reserve_days' => false, 'new_client_bonus_pct' => false]];
    }

    public function test_conditions_publish_and_guard_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $draft = app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['conditions'] = ['approved_by' => '', 'discount_grid' => []];
            $data['guard_rules'] = ['knowledge' => [['id' => 'cap', 'check' => 'pct_above_cap', 'severity' => 'block'], ['id' => 'numbers', 'check' => 'numbers_vs_conditions', 'severity' => 'block']]];

            return app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/conditions', $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/knowledge/conditions')->assertOk()->assertInertia(fn ($page) => $page->where('conditions.min_order_rub', 10000)->where('conditions.approvals.min_order_rub', true));
        $this->post('/seller/knowledge/'.$draft['id'].'/publish', ['version' => 2])->assertRedirect();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $snapshot = app(KnowledgeRepository::class)->published($tenant);
            $this->assertSame(15, $snapshot->conditions()['max_pct_unapproved']);
            $result = (new DeterministicGuard($snapshot))->check('Скидка 20%', new Goal('answer_only'), new LeadState);
            $this->assertNotEmpty($result['issues']);
            $valid = (new DeterministicGuard($snapshot))->check('Минимальный заказ 10 тыс.', new Goal('answer_only'), new LeadState);
            $invalid = (new DeterministicGuard($snapshot))->check('Минимальный заказ 11 тыс.', new Goal('answer_only'), new LeadState);
            $this->assertEmpty($valid['issues']);
            $this->assertNotEmpty($invalid['issues']);
        });
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/conditions')->assertOk()->assertInertia(fn ($page) => $page->where('draft', null));
        $this->post('/seller/knowledge/conditions', $this->input())->assertNotFound();
    }

    public function test_input_conflict_and_permissions(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, fn () => app(KnowledgeRepository::class)->createDraft($tenant, array_fill_keys(KnowledgeSnapshot::FILES, [])));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = $this->input();
        $input['min_order_rub'] = -1;
        $this->post('/seller/knowledge/conditions', $input)->assertSessionHasErrors('min_order_rub');
        $input = $this->input();
        $input['max_pct_unapproved'] = 101;
        $this->post('/seller/knowledge/conditions', $input)->assertSessionHasErrors('max_pct_unapproved');
        $input = $this->input();
        $input['version'] = 9;
        $this->post('/seller/knowledge/conditions', $input)->assertStatus(409);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/conditions')->assertForbidden();
        $this->post('/seller/knowledge/conditions', $this->input())->assertForbidden();
    }
}
