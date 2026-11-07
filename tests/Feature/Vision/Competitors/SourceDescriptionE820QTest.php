<?php

namespace Tests\Feature\Vision\Competitors;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SourceDescriptionE820QTest extends TenancyTestCase
{
    public function test_description_is_a_static_reference_scoped_to_the_current_brand_snapshot(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $snapshot = ['questions' => [], 'metrics' => ['sources' => [['domain' => 'example.test', 'type' => 'catalog', 'answers' => 1, 'description' => 'Catalog']], 'slots' => []]];
        app()->instance(SnapshotView::class, new class($brand->id, $snapshot) extends SnapshotView
        {
            public function __construct(private string $brand, private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $tenant->brandId === $this->brand ? $this->snapshot : null;
            }
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/sources')->assertOk()
            ->assertInertia(fn ($page) => $page->where('sources.0.description', 'Каталог компаний и поставщиков.'));
        $this->post('/vision/sources/presence', ['domain' => 'unknown.test', 'presence' => 'absent'])->assertSessionHasErrors('domain');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('available', false)->has('sources', 0));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/sources/presence', ['domain' => 'example.test', 'presence' => 'absent'])->assertForbidden();
    }
}
