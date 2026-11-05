<?php

namespace Tests\Feature\Vision\Reports;

use Tests\TestCase;

final class PublicInsightsE819ETest extends TestCase
{
    public function test_insights_use_only_available_public_snapshot_metrics(): void
    {
        $data = ['expired' => false, 'brandName' => 'Бренд A', 'publishedAt' => '2026-11-04', 'report' => [
            'visibility' => 64, 'coverage' => null, 'share_of_voice' => 28, 'position' => 2.4, 'questions' => 0,
        ]];
        $html = view('public-report.index', $data)->render();
        self::assertStringContainsString('Видимость бренда: 64,0%', $html);
        self::assertStringContainsString('Доля голоса: 28,0%', $html);
        self::assertStringContainsString('Средняя позиция: 2,4', $html);
        self::assertStringNotContainsString('по сравнению с предыдущим', $html);
        $data['report']['visibility'] = null;
        $data['report']['share_of_voice'] = null;
        $data['report']['position'] = null;
        $html = view('public-report.index', $data)->render();
        self::assertStringContainsString('Проверенных выводов для этого снимка пока нет.', $html);
        self::assertStringNotContainsString('Видимость бренда:', $html);
    }

    public function test_expired_publication_does_not_display_snapshot_or_brand(): void
    {
        $html = view('public-report.index', ['expired' => true, 'brandName' => 'Чужой бренд', 'report' => null, 'publishedAt' => '2026-11-04'])->render();
        self::assertStringContainsString('Ссылка недоступна', $html);
        self::assertStringNotContainsString('Чужой бренд', $html);
    }
}
