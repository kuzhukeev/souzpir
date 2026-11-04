<?php

namespace Tests\Feature\Vision\Competitors;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DistinctCompaniesE820GTest extends TenancyTestCase
{
    public function test_distinct_decision_persists_per_brand_and_requires_write_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $snapshot = ['metrics' => ['entities' => [['name' => 'Альфа', 'answers' => 1, 'is_brand' => false], ['name' => 'ООО Альфа', 'answers' => 1, 'is_brand' => false]], 'slots' => [], 'intents' => []]];
        app()->instance(SnapshotView::class, new class($snapshot) extends SnapshotView
        {
            public function __construct(private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $this->snapshot;
            }
        });
        $data = ['source' => 'Альфа', 'target' => 'ООО Альфа', 'distinct' => true];
        $this->post('/vision/competitors/merge', $data)->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/vision/competitors/merge', $data)->assertSessionHasNoErrors();
        $this->post('/vision/competitors/merge', ['source' => $data['target'], 'target' => $data['source'], 'distinct' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('vision_competitor_distinct', 1);
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->has('distinctPairs', 1));
        $this->post('/vision/competitors/merge', ['source' => 'Альфа', 'target' => 'Альфа', 'distinct' => true])->assertSessionHasErrors('source');
        $this->post('/vision/competitors/merge', ['source' => 'Альфа', 'target' => 'Неизвестная', 'distinct' => true])->assertSessionHasErrors('source');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/competitors/merge', $data)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->has('distinctPairs', 0));
    }
}
