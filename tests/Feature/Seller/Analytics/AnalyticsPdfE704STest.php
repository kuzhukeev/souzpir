<?php

namespace Tests\Feature\Seller\Analytics;

use App\Seller\Analytics\AnalyticsPdf;
use Dompdf\Dompdf;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

final class AnalyticsPdfE704STest extends TestCase
{
    public function test_pdf_contains_manager_name_and_real_daily_values(): void
    {
        $data = ['total' => 3, 'contact' => 2, 'handed' => 1, 'closed' => 1, 'messageCount' => 4, 'botCompleted' => 1,
            'from' => '2026-11-01', 'to' => '2026-11-02', 'channel' => '', 'source' => '', 'manager' => 'id-1',
            'managerOptions' => [['id' => 'id-1', 'name' => 'Ирина Иванова']], 'daily' => ['2026-11-01' => 1, '2026-11-02' => 2], 'topics' => [], 'unknownDialogs' => 0, 'managers' => []];
        $html = AnalyticsPdf::html($data, 'Пример бренда', 'weekly');
        self::assertStringContainsString('width:50%', $html);
        self::assertStringContainsString('width:100%', $html);
        self::assertStringNotContainsString('id-1', $html);
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $text = (new Parser)->parseContent($pdf->output())->getText();
        self::assertStringContainsString('Ирина Иванова', $text);
        self::assertStringContainsString('2026-11-01', $text);
        self::assertStringContainsString('Сообщения', $text);
        $data['daily'] = [];
        self::assertStringContainsString('Данных о динамике за период пока нет.', AnalyticsPdf::html($data, 'Бренд', 'weekly'));
    }
}
