<?php

namespace Tests\Feature\Seller\Knowledge\Fewshot;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FewshotTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_save_preview_and_disable_sample_in_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $item = ['version' => 1, 'bucket' => 'examples', 'tag' => 'ask_city', 'text' => 'В какой город отправить?', 'client_type' => 'all', 'confidence' => 0.9, 'source' => 'manual', 'enabled' => true];
        $this->post('/seller/knowledge/fewshot', $item)->assertRedirect()->assertSessionHasNoErrors();
        $this->getJson('/seller/knowledge/fewshot/preview?tag=ask_city')->assertOk()->assertJsonPath('examples.0.text', 'В какой город отправить?');
        $this->post('/seller/knowledge/fewshot', [...$item, 'version' => 2, 'index' => 0, 'enabled' => false])->assertRedirect()->assertSessionHasNoErrors();
        $this->getJson('/seller/knowledge/fewshot/preview?tag=ask_city')->assertOk()->assertJsonCount(0, 'examples');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->getJson('/seller/knowledge/fewshot/preview?tag=ask_city')->assertNotFound();
    }

    public function test_validation_and_permissions(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/fewshot', ['version' => 1, 'bucket' => 'examples', 'tag' => '', 'text' => '', 'client_type' => 'all', 'confidence' => 2, 'enabled' => true])->assertSessionHasErrors(['tag', 'text', 'confidence']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/fewshot')->assertForbidden();
    }
}
