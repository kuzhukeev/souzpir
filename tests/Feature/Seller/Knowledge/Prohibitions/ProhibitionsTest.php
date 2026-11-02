<?php

namespace Tests\Feature\Seller\Knowledge\Prohibitions;

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

final class ProhibitionsTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fixture(TenantContext $tenant): array
    {
        return app(TenantContextRunner::class)->run($tenant, function () use ($tenant): array {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['closed_topics'] = [['id' => 'staff', 'category' => 'staff', 'idea' => 'Не называем', 'client_examples' => ['Вопрос']]];
            $data['guard_rules'] = ['forbidden_promises' => [['id' => 'one', 'description' => 'Отсрочка', 'severity' => 'block', 'patterns' => ['отсрочка']], ['id' => 'two', 'description' => 'Гарантия', 'severity' => 'block', 'patterns' => ['гарантия']], ['id' => 'three', 'description' => 'Возврат', 'severity' => 'block', 'patterns' => ['возврат']]]];

            return app(KnowledgeRepository::class)->createDraft($tenant, $data);
        });
    }

    private function input(): array
    {
        return ['version' => 1, 'topics' => [['id' => 'staff', 'category' => 'staff', 'idea' => 'Вернуться к заказу', 'next' => 'return']],
            'rules' => [['id' => 'one', 'severity' => 'warn'], ['id' => 'two', 'severity' => 'warn'], ['id' => 'three', 'severity' => 'warn']], 'maxWords' => 80];
    }

    public function test_warn_rules_publish_and_separate_brand(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $draft = $this->fixture($tenant);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/prohibitions', $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/seller/knowledge/'.$draft['id'].'/publish', ['version' => 2])->assertRedirect();
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $snapshot = app(KnowledgeRepository::class)->published($tenant);
            $this->assertSame('Вернуться к заказу', $snapshot->all('closed_topics')[0]['idea']);
            foreach (['отсрочка', 'гарантия', 'возврат'] as $text) {
                $result = (new DeterministicGuard($snapshot))->check($text, new Goal('answer_only'), new LeadState);
                $this->assertNotEmpty($result['warnings']);
                $this->assertEmpty($result['issues']);
            }
        });
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/prohibitions')->assertOk()->assertInertia(fn ($page) => $page->where('draft', null));
        $this->post('/seller/knowledge/prohibitions', $this->input())->assertNotFound();
    }

    public function test_validation_conflict_and_access(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $this->fixture($tenant);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = $this->input();
        $input['maxWords'] = 1;
        $this->post('/seller/knowledge/prohibitions', $input)->assertSessionHasErrors('maxWords');
        $input = $this->input();
        $input['rules'][0]['severity'] = 'wrong';
        $this->post('/seller/knowledge/prohibitions', $input)->assertSessionHasErrors('rules.0.severity');
        $input = $this->input();
        $input['version'] = 8;
        $this->post('/seller/knowledge/prohibitions', $input)->assertStatus(409);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/prohibitions')->assertForbidden();
        $this->post('/seller/knowledge/prohibitions', $this->input())->assertForbidden();
    }
}
