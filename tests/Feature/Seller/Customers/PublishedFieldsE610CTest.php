<?php

namespace Tests\Feature\Seller\Customers;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PublishedFieldsE610CTest extends TenancyTestCase
{
    public function test_published_fields_are_scoped_and_read_only(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['policy'] = ['slot_order' => ['unknown' => ['city']], 'client_fields' => [['id' => 'outlet', 'name' => 'Outlet', 'type' => 'text', 'options' => [], 'required' => true, 'stage' => 'handoff']]];
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $repo->publish($tenant, $draft['id'], 1);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/scenario/fields')->assertOk()
            ->assertInertia(fn ($page) => $page->where('publishedClientFields.0.id', 'outlet')->where('publishedClientFields.0.required', true)->where('publishedClientFields.0.stage', 'handoff'));
        $this->withSession(['brand_id' => $other->id])->get('/seller/scenario/fields')->assertOk()
            ->assertInertia(fn ($page) => $page->has('publishedClientFields', 0));
        $this->withSession(['brand_id' => $brand->id])->post('/seller/scenario/fields/draft', ['version' => 1, 'fields' => [['id' => 'bad code']]])->assertSessionHasErrors();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/scenario/fields/draft', ['version' => 1, 'fields' => []])->assertForbidden();
    }
}
