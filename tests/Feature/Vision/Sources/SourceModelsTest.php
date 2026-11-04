<?php

namespace Tests\Feature\Vision\Sources;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SourceModelsTest extends TenancyTestCase
{
    public function test_models_include_all_slots_not_only_the_first_twenty_examples(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $slots = [];
        for ($i = 0; $i < 21; $i++) {
            $slots[] = ['valid' => true, 'provider' => $i === 20 ? 'second' : 'first', 'sources' => [['domain' => 'example.test', 'url' => 'https://example.test/']], 'mentions' => []];
        }
        $snapshot = ['metrics' => ['sources' => [['domain' => 'example.test', 'answers' => 21]], 'slots' => $slots]];
        app()->instance(SnapshotView::class, new class($snapshot) extends SnapshotView
        {
            public function __construct(private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $this->snapshot;
            }
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('sources.0.models', ['first', 'second'])->has('sources.0.examples', 20));
    }
}
