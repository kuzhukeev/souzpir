<?php

namespace Tests\Feature\Seller\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AnalyticsPdfE704RTest extends TenancyTestCase
{
    public function test_pdf_respects_brand_filters_permissions_and_renders_cyrillic(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('brands')->where('id', $brand->id)->update(['name' => 'Пиротехника Север']);
        foreach ([$brand, $other, $other] as $item) {
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'stage' => 'done', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $response = $this->get('/seller/analytics/report.pdf?report_type=weekly')->assertOk()->assertHeader('content-type', 'application/pdf');
        self::assertStringStartsWith('%PDF-', $response->getContent());
        $text = (new Parser)->parseContent($response->getContent())->getText();
        self::assertStringContainsString('Пиротехника Север', $text);
        self::assertStringContainsString('Сводка владельцу', $text);
        self::assertMatchesRegularExpression('/Диалоги\s+1/u', $text);
        $filtered = $this->get('/seller/analytics/report.pdf?channel=missing')->assertOk();
        self::assertMatchesRegularExpression('/Диалоги\s+0/u', (new Parser)->parseContent($filtered->getContent())->getText());
        foreach (['dialogs' => 'Диалоги', 'topics' => 'Темы и вопросы', 'managers' => 'Воронка по менеджерам'] as $kind => $title) {
            $variant = $this->get('/seller/analytics/report.pdf?report_type='.$kind)->assertOk();
            self::assertStringContainsString($title, (new Parser)->parseContent($variant->getContent())->getText());
        }
        $this->get('/seller/analytics/report.pdf?from=bad')->assertSessionHasErrors('from');
        $this->get('/seller/analytics/report.pdf?report_type=wrong')->assertSessionHasErrors('report_type');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $otherPdf = $this->get('/seller/analytics/report.pdf')->assertOk();
        self::assertMatchesRegularExpression('/Диалоги\s+2/u', (new Parser)->parseContent($otherPdf->getContent())->getText());
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/analytics/report.pdf?report_type=managers')->assertForbidden();
        $this->get('/seller/analytics/report.pdf?manager='.$owner->id)->assertForbidden();
        $this->get('/seller/analytics/report.pdf?report_type=topics')->assertOk();
        auth()->logout();
        $this->get('/seller/analytics/report.pdf')->assertRedirect();
    }
}
