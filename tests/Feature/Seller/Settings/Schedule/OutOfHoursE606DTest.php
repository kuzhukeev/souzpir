<?php

namespace Tests\Feature\Seller\Settings\Schedule;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class OutOfHoursE606DTest extends TenancyTestCase
{
    public function test_phrase_comes_from_published_knowledge_and_is_brand_scoped(): void
    {
        [$owner, $org, $brand, $other, $member] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
            $data['persona'] = ['service_phrases' => ['out_of_hours' => 'Ответим утром']];
            $repo = app(KnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, $data);
            $repo->publish($tenant, $draft['id'], 1);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/settings')->assertOk()->assertInertia(fn ($page) => $page->where('outOfHoursPhrase', 'Ответим утром'));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/settings')->assertOk()->assertInertia(fn ($page) => $page->where('outOfHoursPhrase', null));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/settings')->assertForbidden();
    }
}
