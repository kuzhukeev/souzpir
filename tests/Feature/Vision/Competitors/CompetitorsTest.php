<?php

namespace Tests\Feature\Vision\Competitors;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\RecalculateMergedReport;
use App\Vision\Competitors\SnapshotView;
use App\Vision\Core\VisibilityMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CompetitorsTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_snapshot_preferences_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $slot = ['provider' => 'a', 'question_n' => 1, 'run_no' => 1, 'qid' => 'q1', 'intent' => 'choose', 'status' => 'ok',
            'protocol_valid' => true, 'mentions_complete' => true, 'fact' => ['brand_mentioned' => false, 'brand_recommended' => false],
            'mentions' => [['name' => 'Альфа', 'role' => null], ['name' => 'Альфа ООО', 'role' => null]],
            'sources' => [['domain' => 'example.test', 'url' => 'https://example.test/', 'source_type' => 'media']]];
        $metrics = app(VisibilityMetrics::class)->calculate([$slot], ['a'], 1, 1);
        $snapshot = ['providers' => ['a' => 'model'], 'questions' => [['id' => 'q1']], 'runs' => 1, 'metrics' => $metrics];
        app()->instance(SnapshotView::class, new class($brand->id, $snapshot) extends SnapshotView
        {
            public function __construct(private string $brand, private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $tenant->brandId === $this->brand ? $this->snapshot : null;
            }
        });
        $recalculation = \Mockery::mock(app(RecalculateMergedReport::class));
        $recalculation->shouldReceive('assertRecalculable')->zeroOrMoreTimes();
        $recalculation->shouldReceive('create')->zeroOrMoreTimes()->andReturn('fixture');
        app()->instance(RecalculateMergedReport::class, $recalculation);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->has('entities', 2));
        $this->post('/vision/competitors/track', ['name' => 'Альфа', 'tracked' => true])->assertRedirect();
        $this->post('/vision/competitors/merge', ['source' => 'Альфа ООО', 'target' => 'Альфа'])->assertRedirect();
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->has('entities', 1)->where('entities.0.answers', 1)->where('entities.0.tracked', true));
        $this->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('sources.0.presence', 'unknown')->where('sources.0.examples.0.url', 'https://example.test/')->where('sources.0.examples.0.provider', 'a')->has('sources.0.competitors', 2));
        $this->post('/vision/sources/presence', ['domain' => 'example.test', 'presence' => 'absent'])->assertRedirect();
        $this->get('/vision/sources')->assertOk()->assertInertia(fn ($page) => $page->where('sources.0.presence', 'absent'));
        $this->post('/vision/sources/presence', ['domain' => 'bad.test', 'presence' => 'absent'])->assertSessionHasErrors('domain');
        $this->post('/vision/competitors/merge', ['source' => 'Альфа', 'target' => 'Альфа'])->assertSessionHasErrors('source');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('available', false)->has('entities', 0));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/sources')->assertOk();
        $this->post('/vision/sources/presence', ['domain' => 'example.test', 'presence' => 'absent'])->assertForbidden();
        $this->post('/vision/competitors/track', ['name' => 'Альфа', 'tracked' => true])->assertForbidden();
    }
}
