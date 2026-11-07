<?php

namespace Tests\Feature\Seller\Knowledge\Fewshot;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FewshotPreviewE408CTest extends TenancyTestCase
{
    public function test_preview_returns_bad_examples_only_from_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/fewshot/bad', ['version' => 1, 'text' => 'Непроверенное обещание', 'why' => 'Нет подтверждения'])->assertSessionHasNoErrors();
        $this->getJson('/seller/knowledge/fewshot/preview?tag=ask_city')->assertOk()->assertJsonPath('bad_examples.0.text', 'Непроверенное обещание');
        $this->getJson('/seller/knowledge/fewshot/preview')->assertUnprocessable();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->getJson('/seller/knowledge/fewshot/preview?tag=ask_city')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->getJson('/seller/knowledge/fewshot/preview?tag=ask_city')->assertNotFound();
    }
}
