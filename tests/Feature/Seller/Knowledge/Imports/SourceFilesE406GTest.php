<?php

namespace Tests\Feature\Seller\Knowledge\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SourceFilesE406GTest extends TenancyTestCase
{
    public function test_private_source_download_is_scoped_and_legacy_document_has_no_source(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $old = (string) Str::ulid();
        DB::table('seller_knowledge_import_documents')->insert(['id' => $old, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'old.txt', 'type' => 'txt', 'status' => 'previewed', 'created_at' => now()->subDay(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $result = $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('facts.txt', 'First fact')])->assertOk();
        $id = $result->json('document_id');
        $this->get('/seller/knowledge/imports')->assertOk()->assertInertia(fn ($page) => $page->where('documents.0.has_source', true)->where('documents.1.has_source', false)->missing('documents.0.source_path'));
        $response = $this->get('/seller/knowledge/imports/documents/'.$id.'/download')->assertOk()->assertDownload('facts.txt');
        self::assertSame('First fact', $response->streamedContent());
        $this->get('/seller/knowledge/imports/documents/'.$old.'/download')->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/imports/documents/'.$id.'/download')->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/knowledge/imports/documents/'.$id.'/download')->assertForbidden();
    }
}
