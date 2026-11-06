<?php

namespace Tests\Feature\Seller\Knowledge\Fewshot;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FewshotTagsE410CTest extends TenancyTestCase
{
    public function test_catalog_is_available_for_empty_draft_and_respects_brand_and_permissions(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $tags = $this->get('/seller/knowledge/fewshot')->assertOk()->inertiaProps('availableTags');
        self::assertCount(73, $tags);
        self::assertContains('open_greeting', $tags);
        self::assertContains('ask_city', $tags);
        self::assertContains('opt_out_ack', $tags);
        $this->post('/seller/knowledge/fewshot', ['version' => 1, 'bucket' => 'examples', 'tag' => 'ask_city', 'text' => 'Какой город?', 'situation' => 'Знакомство', 'client_type' => 'all', 'confidence' => 0.8, 'source' => 'manual', 'enabled' => true])->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('Какой город?', $this->get('/seller/knowledge/fewshot')->assertOk()->inertiaProps('fewshot.examples.0.text'));
        $this->post('/seller/knowledge/fewshot', ['version' => 2, 'bucket' => 'examples', 'tag' => '', 'text' => '', 'client_type' => 'all', 'confidence' => 0.8, 'enabled' => true])->assertSessionHasErrors(['tag', 'text']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        self::assertNull($this->get('/seller/knowledge/fewshot')->assertOk()->inertiaProps('fewshot'));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/knowledge/fewshot')->assertForbidden();
    }
}
