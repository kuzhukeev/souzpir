<?php

namespace Tests\Feature\Seller\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SpeechSamplesE704JTest extends TenancyTestCase
{
    public function test_created_samples_are_counted_for_author_period_and_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $sample = ['version' => 1, 'bucket' => 'examples', 'tag' => 'hello', 'text' => 'Hello', 'client_type' => 'all', 'confidence' => 0.9, 'enabled' => true];
        $this->post('/seller/knowledge/fewshot', $sample)->assertSessionHasNoErrors();
        $this->post('/seller/knowledge/fewshot', [...$sample, 'version' => 2, 'index' => 0])->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('audit_logs')->where('action', 'knowledge.fewshot.added')->where('actor_id', $owner->id)->where('brand_id', $brand->id)->count());
        $this->get('/seller/analytics')->assertOk()->assertInertia(fn ($page) => $page->where('analytics.managers.0.samples', 1));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/analytics')->assertOk()->assertInertia(fn ($page) => $page->has('analytics.managers', 0));
        $this->getJson('/seller/analytics?from=bad')->assertUnprocessable();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/analytics?manager='.$owner->id)->assertForbidden();
    }
}
