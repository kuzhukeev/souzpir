<?php

namespace Tests\Feature\Seller\Knowledge\Fewshot;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FewshotLoadingE410KTest extends TenancyTestCase
{
    public function test_deferred_load_rescues_failure_and_rechecks_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $url = '/seller/knowledge/fewshot';
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Seller/Knowledge/Fewshot/Index', 'X-Inertia-Partial-Data' => 'fewshotData'];
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->missing('fewshotData'));
        $this->get($url, $headers)->assertOk()->assertJsonPath('props.fewshotData.draft.version', 1);
        $this->mock(KnowledgeRepository::class, fn ($mock) => $mock->shouldReceive('forRelease')->andThrow(new \RuntimeException('Fixture failure')));
        $this->get($url, $headers)->assertOk()->assertJsonPath('rescuedProps.0', 'fewshotData');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get($url, $headers)->assertForbidden();
    }
}
