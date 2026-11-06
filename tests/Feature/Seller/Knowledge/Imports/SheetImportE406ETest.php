<?php

namespace Tests\Feature\Seller\Knowledge\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;
use ZipArchive;

final class SheetImportE406ETest extends TenancyTestCase
{
    private function workbook(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'e406e');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Первый" r:id="rId1"/><sheet name="Второй" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row><c r="A1" t="inlineStr"><is><t>Первый факт</t></is></c></row></sheetData></worksheet>');
        $zip->addFromString('xl/worksheets/sheet2.xml', '<worksheet><sheetData><row><c r="A1" t="inlineStr"><is><t>Второй факт</t></is></c></row></sheetData></worksheet>');
        $zip->close();
        $file = UploadedFile::fake()->createWithContent('facts.xlsx', file_get_contents($path));
        unlink($path);

        return $file;
    }

    public function test_sheet_selection_reuses_scoped_document_and_marks_imported(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $first = $this->postJson('/seller/knowledge/imports/preview', ['file' => $this->workbook()])->assertOk()->assertJsonPath('sheets.1', 'Второй')->assertJsonPath('proposals.0', 'Первый факт');
        $id = $first->json('document_id');
        $this->getJson('/seller/knowledge/imports/documents/'.$id.'/preview?sheet=1')->assertOk()->assertJsonPath('sheets.1', 'Второй')->assertJsonPath('proposals.0', 'Второй факт');
        $this->postJson('/seller/knowledge/imports/preview', ['file' => $this->workbook(), 'document_id' => $id, 'sheet' => 1])->assertOk()->assertJsonPath('document_id', $id)->assertJsonPath('proposals.0', 'Второй факт');
        self::assertSame(1, DB::table('seller_knowledge_import_documents')->where('brand_id', $brand->id)->count());
        $this->postJson('/seller/knowledge/imports/preview', ['file' => $this->workbook(), 'sheet' => 8])->assertUnprocessable();
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'imported'])->assertRedirect();
        $this->post('/seller/knowledge/imports', ['version' => 2, 'group' => 'imported', 'facts' => ['Второй факт'], 'document_id' => $id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('seller_knowledge_import_documents', ['id' => $id, 'status' => 'imported']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->postJson('/seller/knowledge/imports/preview', ['file' => $this->workbook(), 'document_id' => $id])->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $other->id])->postJson('/seller/knowledge/imports/preview', ['file' => $this->workbook()])->assertForbidden();
    }
}
