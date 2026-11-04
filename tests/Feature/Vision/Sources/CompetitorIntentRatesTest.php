<?php

namespace Tests\Feature\Vision\Sources;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CompetitorIntentRatesTest extends TenancyTestCase
{
    public function test_rates_use_confirmed_snapshot_and_are_brand_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $snapshot = ['metrics' => ['entities' => [['name' => 'Альфа', 'answers' => 1, 'is_brand' => false]], 'slots' => [], 'intents' => ['choose' => ['entities' => [['name' => 'Альфа', 'frequency' => 50, 'denominator' => 2]]], 'price' => ['entities' => []]]]];
        app()->instance(SnapshotView::class, new class($brand->id, $snapshot) extends SnapshotView
        {
            public function __construct(private string $brand, private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $tenant->brandId === $this->brand ? $this->snapshot : null;
            }
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('entities.0.intent_rates.choose', 50)->where('entities.0.intent_rates.price', null)->where('entities.0.intent_denominators.choose', 2));
        $this->post('/vision/competitors/merge', ['source' => 'Альфа', 'target' => 'Альфа'])->assertSessionHasErrors('source');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/competitors/merge', ['source' => 'Альфа', 'target' => 'Другая'])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('available', false)->has('entities', 0));
    }
}
