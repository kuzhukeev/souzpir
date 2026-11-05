<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FieldsE603GTest extends TenancyTestCase
{
    public function test_fields_use_published_brand_policy_and_are_read_only(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['policy'] = ['slot_order' => ['unknown' => ['city', 'purpose'], 'for_self' => ['purpose']], 'missing_question_tags' => ['city' => 'ask_city']];
            $data['fewshot'] = ['examples' => [['tag' => 'ask_city', 'text' => 'В каком вы городе?', 'client_type' => 'all', 'confidence' => 0.9]]];
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $repo->publish($tenant, $draft['id'], 1);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/scenario/fields')->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Settings/Policy/Fields')->where('available', true)->has('fields', count(LeadState::SLOTS))->where('fields.1.id', 'city')->where('fields.1.example', 'В каком вы городе?')->where('fields.1.priority', 1)->where('fields.1.absent_from_order.0', 'for_self'));
        $this->post('/seller/scenario/fields')->assertStatus(405);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/scenario/fields')->assertOk()->assertInertia(fn ($page) => $page->where('available', false)->has('fields', 0));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/scenario/fields')->assertOk();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/scenario/fields/not-a-route')->assertNotFound();
    }
}
