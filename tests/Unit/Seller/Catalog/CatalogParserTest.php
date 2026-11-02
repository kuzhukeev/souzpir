<?php

namespace Tests\Unit\Seller\Catalog;

use App\Seller\Catalog\CatalogImporter;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeSnapshot;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use ZipArchive;

final class CatalogParserTest extends TestCase
{
    public function test_xlsx_inline_cells_are_parsed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog');
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Товары" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row><c r="A1" t="inlineStr"><is><t>Название</t></is></c><c r="B1" t="inlineStr"><is><t>Цена</t></is></c></row><row><c r="A2" t="inlineStr"><is><t>Хлопушка</t></is></c><c r="B2"><v>120</v></c></row></sheetData></worksheet>');
        $zip->close();
        try {
            $items = app(CatalogImporter::class)->parse($path, 'xlsx');
            self::assertSame('Хлопушка', $items[0]['name']);
            self::assertSame('120', $items[0]['price']);
        } finally {
            unlink($path);
        }
    }

    public function test_disabled_catalog_keeps_guard_price_claim_check(): void
    {
        $data = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $data[$file] = Yaml::parseFile(base_path('tests/Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml'));
        }
        $guard = new DeterministicGuard(new KnowledgeSnapshot($data, 'fixture', 'fixture'));
        self::assertSame('regenerate', $guard->check('Прайс в чате, там можно посмотреть позиции. А что планируете брать?', new Goal('answer_and_ask'), new LeadState)['verdict']);
        $price = $guard->check('Уточню: цена 120 рублей.', new Goal('unknown_fact'), new LeadState);
        self::assertNotEmpty($price['warnings']);
    }
}
