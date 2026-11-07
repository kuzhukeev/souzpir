<?php

namespace Tests\Feature\Vision\Competitors;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\RecalculateMergedReport;
use App\Vision\Competitors\SnapshotView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MultiMergeE820VTest extends TenancyTestCase
{
    public function test_multiple_names_merge_and_validate_brand_and_rights(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $snapshot = ['metrics' => ['entities' => array_map(fn ($name) => ['name' => $name, 'answers' => 1, 'is_brand' => false], ['Альфа', 'ООО Альфа', 'Альфа магазин']), 'slots' => [], 'intents' => []]];
        app()->instance(SnapshotView::class, new class($snapshot) extends SnapshotView
        {
            public function __construct(private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $this->snapshot;
            }
        });
        $this->post('/vision/competitors/merge', [])->assertRedirect();
        $recalculation = \Mockery::mock(app(RecalculateMergedReport::class));
        $recalculation->shouldReceive('create')->zeroOrMoreTimes()->andReturn('fixture');
        app()->instance(RecalculateMergedReport::class, $recalculation);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/competitors/merge', ['sources' => ['Альфа', 'Альфа'], 'target' => 'ООО Альфа'])->assertSessionHasErrors();
        $this->post('/vision/competitors/merge', ['sources' => ['Альфа', 'ООО Альфа'], 'target' => 'ООО Альфа'])->assertSessionHasErrors();
        $this->post('/vision/competitors/merge', ['sources' => ['Альфа', 'Чужая'], 'target' => 'ООО Альфа'])->assertSessionHasErrors();
        $this->post('/vision/competitors/merge', ['sources' => ['Альфа', 'Альфа магазин'], 'target' => 'ООО Альфа'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vision_competitor_aliases', ['brand_id' => $brand->id, 'alias' => 'Альфа', 'canonical' => 'ООО Альфа']);
        $this->assertDatabaseHas('vision_competitor_aliases', ['brand_id' => $brand->id, 'alias' => 'Альфа магазин', 'canonical' => 'ООО Альфа']);
        $this->assertDatabaseMissing('vision_competitor_aliases', ['brand_id' => $other->id, 'alias' => 'Альфа']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/competitors/merge', ['sources' => ['Альфа'], 'target' => 'ООО Альфа'])->assertForbidden();
    }
}
