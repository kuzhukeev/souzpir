<?php

namespace Tests\Feature\Seller\Knowledge\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ImportsTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_file_preview_and_selected_facts_saved_only_to_active_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'imported'])->assertRedirect();
        $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('facts.txt', "Первый факт\nВторой факт")])->assertOk()->assertJsonPath('proposals.0', 'Первый факт');
        $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('facts.csv', "Товар;Цена\nТорт;500")])->assertOk()->assertJsonPath('proposals.1', 'Торт · 500');
        $this->post('/seller/knowledge/imports', ['version' => 2, 'group' => 'imported', 'facts' => ['Первый факт']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('kn_facts', ['text' => 'Первый факт', 'source' => 'import']);
        $this->assertDatabaseMissing('kn_facts', ['text' => 'Второй факт']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->post('/seller/knowledge/imports', ['version' => 2, 'group' => 'imported', 'facts' => ['Чужой факт']])->assertNotFound();
        $this->assertDatabaseMissing('kn_facts', ['text' => 'Чужой факт']);
    }

    public function test_validation_and_viewer_permission(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('bad.pdf', 'test')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/seller/knowledge/imports', ['version' => 1, 'group' => 'x', 'facts' => []])->assertSessionHasErrors('facts');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/knowledge/imports')->assertForbidden();
        $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('x.txt', 'test')])->assertForbidden();
    }
}
