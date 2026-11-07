<?php

namespace Tests\Feature\Vision\Sources;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CompetitorComparableAnswersE820ETest extends TenancyTestCase
{
    public function test_only_complete_identified_answers_form_a_comparable_base(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $slots = [
            ['valid' => true, 'intent' => 'choose', 'provider' => 'a', 'question_n' => 1, 'run_no' => 1, 'mentions_complete' => true, 'mentions' => []],
            ['valid' => true, 'intent' => 'price', 'provider' => 'a', 'question_n' => 2, 'run_no' => 1, 'mentions_complete' => false, 'mentions' => []],
        ];
        $entity = ['name' => 'Альфа', 'answers' => 1, 'is_brand' => false];
        $group = ['entities' => [['name' => 'Альфа', 'frequency' => 100, 'denominator' => 1]]];
        $snapshot = ['metrics' => ['entities' => [$entity], 'slots' => $slots, 'intents' => ['choose' => $group, 'price' => $group]]];
        app()->instance(SnapshotView::class, new class($brand->id, $snapshot) extends SnapshotView
        {
            public function __construct(private string $brand, private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $tenant->brandId === $this->brand ? $this->snapshot : null;
            }
        });
        $this->get('/vision/competitors')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('entities.0.intent_bases.choose', hash('sha256', '["a",1,1]'))->where('entities.0.intent_bases.price', null));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/competitors/merge', ['source' => 'Альфа', 'target' => 'Бета'])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('available', false)->has('entities', 0));
    }
}
