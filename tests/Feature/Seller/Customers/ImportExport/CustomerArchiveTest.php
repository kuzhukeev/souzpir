<?php

namespace Tests\Feature\Seller\Customers\ImportExport;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;
use ZipArchive;

final class CustomerArchiveTest extends TenancyTestCase
{
    public function test_archive_contains_only_selected_brand_and_empty_archives_are_available(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$brand, 'Своя', 'Тверь'], [$other, 'Чужая', 'Казань']] as [$item, $name, $city]) {
            DB::table('contacts')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'name' => $name, 'city' => $city, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $range = ['module' => 'seller', 'from' => now()->subDay()->format('Y-m-d'), 'to' => now()->addDay()->format('Y-m-d')];
        $this->get('/settings/data')->assertOk()->assertInertia(fn ($page) => $page->where('modules.0.value', 'seller'));
        $this->post('/settings/data/exports', $range)->assertRedirect()->assertSessionHasNoErrors();
        $archive = DB::table('data_exports')->where('brand_id', $brand->id)->first();
        self::assertNotNull($archive);
        $zip = new ZipArchive;
        self::assertTrue($zip->open(Storage::disk('local')->path($archive->path)));
        $data = $zip->getFromName('records.jsonl');
        $csv = $zip->getFromName('customers.csv');
        $xlsx = $zip->getFromName('customers.xlsx');
        self::assertIsString($csv);
        self::assertStringContainsString('Своя', $csv);
        self::assertStringNotContainsString('Чужая', $csv);
        self::assertIsString($xlsx);
        $spreadsheetPath = tempnam(sys_get_temp_dir(), 'customer-xlsx-');
        file_put_contents($spreadsheetPath, $xlsx);
        $spreadsheet = new ZipArchive;
        self::assertTrue($spreadsheet->open($spreadsheetPath));
        $sheet = $spreadsheet->getFromName('xl/worksheets/sheet1.xml');
        self::assertStringContainsString('Своя', $sheet);
        self::assertStringNotContainsString('Чужая', $sheet);
        self::assertNotFalse(simplexml_load_string($sheet));
        $spreadsheet->close();
        unlink($spreadsheetPath);
        $zip->close();
        self::assertStringContainsString('Своя', $data);
        self::assertStringContainsString('Тверь', $data);
        self::assertStringNotContainsString('Чужая', $data);
        $this->get('/settings/data/exports/'.$archive->id.'/download')->assertOk();
        $this->post('/settings/data/exports', ['module' => 'seller', 'from' => '2001-01-01', 'to' => '2001-01-02'])->assertRedirect()->assertSessionHasNoErrors();
        $empty = DB::table('data_exports')->where('period_from', '2001-01-01')->first();
        self::assertTrue($zip->open(Storage::disk('local')->path($empty->path)));
        self::assertSame('', $zip->getFromName('records.jsonl'));
        self::assertStringContainsString('Имя', $zip->getFromName('customers.csv'));
        self::assertIsString($zip->getFromName('customers.xlsx'));
        $zip->close();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/settings/data/exports', $range)->assertForbidden();
        $this->get('/settings/data/exports/'.$archive->id.'/download')->assertForbidden();
    }
}
