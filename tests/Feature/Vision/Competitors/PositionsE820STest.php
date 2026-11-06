<?php

namespace Tests\Feature\Vision\Competitors;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PositionsE820STest extends TenancyTestCase
{
    public function test_card_uses_only_complete_confirmed_questions_without_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $snapshot = ['questions' => [['id' => 'q1', 'text' => 'Как выбрать?'], ['id' => 'q2', 'text' => 'Сколько стоит?']], 'metrics' => [
            'entities' => [['name' => 'Конкурент', 'answers' => 2, 'is_brand' => false, 'position' => ['average' => 2.5, 'count' => 2]]], 'intents' => [],
            'slots' => [
                ['valid' => true, 'mentions_complete' => true, 'qid' => 'q1', 'provider' => 'fake', 'intent' => 'choose', 'fact' => ['brand_mentioned' => false], 'mentions' => [['name' => 'Конкурент']], 'sources' => []],
                ['valid' => true, 'mentions_complete' => true, 'qid' => 'q2', 'provider' => 'fake', 'intent' => 'price', 'fact' => ['brand_mentioned' => true], 'mentions' => [['name' => 'Конкурент']], 'sources' => []],
                ['valid' => false, 'mentions_complete' => true, 'qid' => 'q2', 'provider' => 'fake', 'intent' => 'price', 'fact' => ['brand_mentioned' => false], 'mentions' => [['name' => 'Конкурент']], 'sources' => []],
            ],
        ]];
        app()->instance(SnapshotView::class, new class($brand->id, $snapshot) extends SnapshotView
        {
            public function __construct(private string $brandId, private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $tenant->brandId === $this->brandId ? $this->snapshot : null;
            }
        });

        $this->get('/vision/competitors')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('entities.0.position.average', 2.5)->where('entities.0.position.count', 2)->where('entities.0.question_count', 2)->where('entities.0.question_total', 2)->has('entities.0.questions_without_brand', 1)->where('entities.0.questions_without_brand.0.text', 'Как выбрать?')->where('entities.0.questions_without_brand.0.models', ['fake']));
        $this->post('/vision/competitors/track', ['name' => 'Конкурент', 'tracked' => true])->assertRedirect();
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('entities.0.tracked', true));
        $this->post('/vision/competitors/track', ['name' => 'Нет такой компании', 'tracked' => true])->assertSessionHasErrors('name');
        $this->post('/vision/competitors/track', ['name' => 'Конкурент', 'tracked' => 'wrong'])->assertSessionHasErrors('tracked');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('available', false)->has('entities', 0));
    }
}
