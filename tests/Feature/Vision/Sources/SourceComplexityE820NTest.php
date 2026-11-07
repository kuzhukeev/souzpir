<?php

namespace Tests\Feature\Vision\Sources;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SourceComplexityE820NTest extends TenancyTestCase
{
    public function test_complexity_and_description_come_from_static_rules_by_type(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->snapshot(['metrics' => ['sources' => [
            ['domain' => 'catalog.test', 'answers' => 1, 'type' => 'catalog', 'url_count' => 1],
            ['domain' => 'media.test', 'answers' => 1, 'type' => 'media', 'url_count' => 1],
            ['domain' => 'ambiguous.test', 'answers' => 1, 'type' => 'catalog', 'type_conflict' => true, 'url_count' => 1],
            ['domain' => 'wellindexed.test', 'answers' => 1, 'type' => 'media', 'url_count' => 3],
        ]]]);

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/sources')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sources.0.complexity', 'просто')->where('sources.0.description', 'Каталог компаний и поставщиков.')
                ->where('sources.1.complexity', 'сложно')->where('sources.1.description', 'Отраслевой медиа-ресурс.')
                ->where('sources.2.complexity', 'средне')
                ->where('sources.3.complexity', 'средне'));
    }

    private function snapshot(array $snapshot): void
    {
        app()->instance(SnapshotView::class, new class($snapshot) extends SnapshotView
        {
            public function __construct(private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $this->snapshot;
            }
        });
    }
}
