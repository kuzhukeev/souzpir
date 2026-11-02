<?php

namespace Tests\Feature\Seller\Catalog;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;
use ZipArchive;

final class ColumnMappingTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function workbook(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Старый" r:id="rId1"/><sheet name="Новый" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet([['Название', 'Цена'], ['Старый товар', '10']]));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheet([['Код', 'Стоимость', 'Имя товара'], ['N2', '25', 'Новый товар']]));
        $zip->close();
        $file = UploadedFile::fake()->createWithContent('catalog.xlsx', file_get_contents($path));
        unlink($path);

        return $file;
    }

    private function sheet(array $rows): string
    {
        $xml = '<worksheet><sheetData>';
        foreach ($rows as $number => $row) {
            $xml .= '<row>';
            foreach ($row as $column => $value) {
                $xml .= '<c r="'.chr(65 + $column).($number + 1).'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    public function test_preview_and_mapped_second_sheet_replace_only_active_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/import', ['file' => $this->workbook(), 'preview' => 1, 'sheet' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('sheets.1', 'Новый')->assertJsonPath('header.2', 'Имя товара');
        $this->post('/seller/integrations/catalog/import', ['file' => $this->workbook(), 'sheet' => 1, 'columns' => ['name' => 2, 'sku' => 0, 'price' => 1]])->assertRedirect();
        $this->assertDatabaseHas('catalog_items', ['brand_id' => $brand->id, 'name' => 'Новый товар', 'sku' => 'N2', 'price' => '25.000']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->has('items', 0));
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('other.csv', "Code;Label;Cost\nB3;Другой товар;40\n"), 'columns' => ['name' => 1, 'sku' => 0, 'price' => 2]])->assertRedirect();
        $this->assertDatabaseHas('catalog_items', ['brand_id' => $other->id, 'name' => 'Другой товар']);
        $this->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('items.0.name', 'Новый товар')->has('items', 1));
    }

    public function test_bad_mapping_and_sheet_keep_previous_catalog_and_viewer_cannot_preview(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/import', ['file' => UploadedFile::fake()->createWithContent('ok.csv', "Название;Цена\nСохранённый товар;50\n")])->assertRedirect();
        $this->post('/seller/integrations/catalog/import', ['file' => $this->workbook(), 'sheet' => 1, 'columns' => ['name' => 2, 'price' => 2]])->assertSessionHasErrors('file');
        $this->post('/seller/integrations/catalog/import', ['file' => $this->workbook(), 'sheet' => 99, 'columns' => ['name' => 2]])->assertSessionHasErrors('file');
        $this->get('/seller/integrations/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('items.0.name', 'Сохранённый товар')->has('items', 1));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/integrations/catalog/import', ['file' => $this->workbook(), 'preview' => 1], ['Accept' => 'application/json'])->assertForbidden();
    }
}
