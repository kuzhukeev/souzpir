<?php

namespace Tests\Feature\Seller\Knowledge\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SavedPreviewE406HTest extends TenancyTestCase
{
    public function test_saved_source_can_be_previewed_without_reupload_and_is_brand_scoped(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $id = $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('facts.txt', "First fact\nSecond fact")])->assertOk()->json('document_id');
        $url = '/seller/knowledge/imports/documents/'.$id.'/preview';
        $this->getJson($url)->assertOk()->assertJsonPath('proposals.0', 'First fact')->assertJsonPath('proposals.1', 'Second fact')->assertJsonPath('document_id', $id)->assertJsonMissingPath('document.source_path');
        $this->getJson($url.'?sheet=100')->assertUnprocessable()->assertJsonValidationErrors('sheet');
        self::assertSame(1, DB::table('seller_knowledge_import_documents')->count());
        $old = (string) Str::ulid();
        DB::table('seller_knowledge_import_documents')->insert(['id' => $old, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'old.txt', 'type' => 'txt', 'status' => 'previewed', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/seller/knowledge/imports/documents/'.$old.'/preview')->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->getJson($url)->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->getJson($url)->assertForbidden();
    }
}
