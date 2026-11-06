<?php

namespace Tests\Feature\Seller\Knowledge\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ImportCountsE406FTest extends TenancyTestCase
{
    public function test_fragment_counts_survive_reload_and_are_brand_scoped(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $response = $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('facts.txt', "First fact\nSecond fact")])
            ->assertOk()->assertJsonPath('document.fragments_count', 2);
        $id = $response->json('document_id');
        $this->assertDatabaseHas('seller_knowledge_import_documents', ['id' => $id, 'fragments_count' => 2]);
        $this->get('/seller/knowledge/imports')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Seller/Knowledge/Imports/Index')
            ->where('documents.0.id', $id)
            ->where('documents.0.fragments_count', 2));
        $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('facts.txt', 'One fact'), 'document_id' => $id])
            ->assertOk()->assertJsonPath('document.fragments_count', 1);
        $this->assertDatabaseHas('seller_knowledge_import_documents', ['id' => $id, 'fragments_count' => 1]);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('facts.txt', 'Foreign'), 'document_id' => $id])->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/knowledge/imports')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('facts.txt', '')])->assertUnprocessable();
    }
}
