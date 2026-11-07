<?php

namespace Tests\Feature\Vision\Competitors;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use App\Vision\Core\VisibilityMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class IntentPositionsE820TTest extends TenancyTestCase
{
    public function test_company_card_exposes_rate_and_position_per_intent(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $slots = [
            ['provider' => 'a', 'question_n' => 1, 'run_no' => 1, 'qid' => 'q1', 'intent' => 'choose', 'status' => 'ok',
                'protocol_valid' => true, 'mentions_complete' => true, 'fact' => ['brand_mentioned' => false, 'brand_recommended' => false],
                'mentions' => [['name' => 'Конкурент', 'role' => null, 'position' => 2]], 'sources' => []],
            ['provider' => 'a', 'question_n' => 2, 'run_no' => 1, 'qid' => 'q2', 'intent' => 'price', 'status' => 'ok',
                'protocol_valid' => true, 'mentions_complete' => true, 'fact' => ['brand_mentioned' => false, 'brand_recommended' => false],
                'mentions' => [], 'sources' => []],
        ];
        $metrics = app(VisibilityMetrics::class)->calculate($slots, ['a'], 2, 1);
        $snapshot = ['providers' => ['a' => 'model'], 'questions' => [['id' => 'q1'], ['id' => 'q2']], 'runs' => 1, 'metrics' => $metrics];
        app()->instance(SnapshotView::class, new class($brand->id, $snapshot) extends SnapshotView
        {
            public function __construct(private string $brand, private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $tenant->brandId === $this->brand ? $this->snapshot : null;
            }
        });

        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page
            ->where('entities.0.intent_rates.choose', 100)
            ->where('entities.0.intent_positions.choose', ['average' => 2, 'count' => 1])
            ->where('entities.0.intent_rates.price', null)
            ->where('entities.0.intent_positions.price', null));
    }
}
