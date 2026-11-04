<?php

namespace Tests\Feature\Vision\Competitors;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use App\Vision\Competitors\SourceRanking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SourceRankingE820MTest extends TenancyTestCase
{
    public function test_ranking_counts_each_domain_once_per_answer_and_limits_to_three(): void
    {
        $slots = [
            ['sources' => [['domain' => 'z.test'], ['domain' => 'a.test'], ['domain' => 'a.test'], ['domain' => ''], ['domain' => null]]],
            ['sources' => [['domain' => 'z.test'], ['domain' => 'b.test']]],
            ['sources' => [['domain' => 'z.test'], ['domain' => 'c.test']]],
        ];
        self::assertSame([['domain' => 'z.test', 'answers' => 3], ['domain' => 'a.test', 'answers' => 1], ['domain' => 'b.test', 'answers' => 1]], app(SourceRanking::class)->forSlots($slots));
        self::assertSame([], app(SourceRanking::class)->forSlots([['sources' => []]]));
    }

    public function test_screen_ranks_only_matching_valid_answers_and_respects_tenant_access(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $snapshot = ['metrics' => ['entities' => [['name' => 'Альфа', 'answers' => 2, 'is_brand' => false]], 'intents' => [], 'slots' => [
            ['valid' => true, 'mentions' => [['name' => 'Альфа']], 'sources' => [['domain' => 'b.test'], ['domain' => 'a.test']]],
            ['valid' => true, 'mentions' => [['name' => 'Альфа']], 'sources' => [['domain' => 'b.test'], ['domain' => 'b.test']]],
            ['valid' => false, 'mentions' => [['name' => 'Альфа']], 'sources' => [['domain' => 'hidden.test']]],
            ['valid' => true, 'mentions' => [['name' => 'Другая']], 'sources' => [['domain' => 'other.test']]],
        ]]];
        app()->instance(SnapshotView::class, new class($brand->id, $snapshot) extends SnapshotView
        {
            public function __construct(private string $brand, private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $tenant->brandId === $this->brand ? $this->snapshot : null;
            }
        });
        $this->get('/vision/competitors')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('entities.0.source_ranking', [['domain' => 'b.test', 'answers' => 2], ['domain' => 'a.test', 'answers' => 1]]));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/competitors/track', ['name' => 'Альфа', 'tracked' => true])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('available', false)->has('entities', 0));
    }
}
