<?php

namespace Tests\Feature\Seller\Settings\Policy;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PolicyLoadE603YTest extends TenancyTestCase
{
    public function test_initial_loading_and_error_are_distinct_and_brand_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('policy', null)->where('policyLoadError', false));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('policy', null)->where('policyLoadError', false));
        $repository = Mockery::mock(KnowledgeRepository::class);
        $repository->shouldReceive('published')->once()->andThrow(new \RuntimeException('Fixture unavailable'));
        $this->app->instance(KnowledgeRepository::class, $repository);
        $this->get('/seller/settings/policy')->assertOk()->assertInertia(fn ($page) => $page->where('policy', null)->where('policyLoadError', true));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/settings/followups', ['enabled' => true, 'first_hours' => 4, 'second_hours' => null, 'max_auto' => 2, 'window' => null, 'texts' => ['Напоминание'], 'agreed_text' => ''])->assertForbidden();
    }
}
