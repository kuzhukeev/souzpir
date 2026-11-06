<?php

namespace Tests\Feature\Vision\Competitors;

use App\Vision\Core\VisibilityMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PositionsE820RTest extends TenancyTestCase
{
    public function test_only_complete_confirmed_positions_are_aggregated(): void
    {
        $slot = fn (string $provider, int $question, string $intent, bool $complete, mixed $position): array => [
            'provider' => $provider, 'question_n' => $question, 'run_no' => 1, 'intent' => $intent, 'status' => 'ok',
            'fact' => ['brand_mentioned' => false, 'mention_status' => 'not_mentioned'],
            'mentions_complete' => $complete, 'mentions' => [['name' => 'Альфа', 'position' => $position]],
        ];
        $result = app(VisibilityMetrics::class)->calculate([
            $slot('a', 1, 'choose', true, 1), $slot('a', 2, 'price', true, 3),
            $slot('b', 1, 'choose', false, 9), $slot('b', 2, 'price', true, null),
        ], ['a', 'b'], 2, 1);
        $entity = $result['entities'][0];
        self::assertSame(['average' => 2.0, 'count' => 2], $entity['position']);
        self::assertSame(['average' => 2.0, 'count' => 2], $entity['positions_by_provider']['a']);
        self::assertArrayNotHasKey('b', $entity['positions_by_provider']);
        self::assertSame(['average' => 1.0, 'count' => 1], $entity['positions_by_intent']['choose']);
    }

    public function test_competitor_page_keeps_brand_and_permission_boundaries(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('available', false));
        $this->post('/vision/competitors/track', ['name' => '', 'tracked' => true])->assertSessionHasErrors('name');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->has('entities', 0));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/competitors/track', ['name' => 'Альфа', 'tracked' => true])->assertForbidden();
    }
}
