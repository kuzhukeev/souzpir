<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FunnelFieldsE603NTest extends TenancyTestCase
{
    public function test_published_stage_fields_are_brand_scoped_and_read_only_for_viewer(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['policy'] = ['stages' => ['need' => ['next' => 'budget', 'when' => 'Условия заполнены']], 'client_fields' => [['id' => 'outlet', 'name' => 'Формат точки', 'type' => 'text', 'required' => true, 'stage' => 'need']]];
            $repository = app(KnowledgeRepository::class);
            $draft = $repository->createDraft($tenant, $data);
            $repository->publish($tenant, $draft['id'], 1);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('policy.client_fields.0.name', 'Формат точки')->where('policy.client_fields.0.required', true)->where('policy.client_fields.0.stage', 'need'));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('canWrite', false));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('policy', null));
    }
}
