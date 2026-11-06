<?php

namespace Tests\Feature\Vision\Reports;

use Tests\TestCase;

final class PublicBlocksE819JTest extends TestCase
{
    public function test_public_blocks_use_snapshot_counts_without_private_answers(): void
    {
        $html = view('public-report.index', ['expired' => false, 'brandName' => 'Бренд', 'publishedAt' => '2026-11-01', 'report' => [
            'visibility' => 64, 'share_of_voice' => 28, 'position' => 2.4, 'questions' => 24,
            'verified_errors' => 3, 'confirmed_facts' => 21, 'reviewed_facts' => 24,
            'competitors' => [['name' => 'Компания А', 'answers' => 28], ['name' => 'Компания Б', 'answers' => 14]],
            'site_readiness' => 72, 'site_criteria' => [['title' => 'Доступность сайта', 'status' => 'pass']],
            'private_answer' => 'PRIVATE_ANSWER_DO_NOT_PUBLISH',
        ]])->render();
        self::assertStringContainsString('class="insights-grid"', $html);
        self::assertStringContainsString('width:50%', $html);
        self::assertStringContainsString('<span>Верно</span><strong>18</strong>', $html);
        self::assertStringContainsString('<span>Требует уточнения</span><strong>3</strong>', $html);
        self::assertStringContainsString('Доступность сайта', $html);
        self::assertStringContainsString('--score: 72', $html);
        self::assertStringNotContainsString('PRIVATE_ANSWER_DO_NOT_PUBLISH', $html);
    }

    public function test_missing_data_and_old_snapshot_do_not_invent_values(): void
    {
        $report = ['visibility' => null, 'share_of_voice' => null, 'position' => null, 'questions' => 0];
        $data = ['expired' => false, 'brandName' => 'Бренд', 'publishedAt' => '2026-11-01', 'report' => $report];
        $html = view('public-report.index', $data)->render();
        self::assertStringContainsString('Нет подтверждённых упоминаний компаний.', $html);
        self::assertStringContainsString('Счётчики проверенных утверждений в этой публикации недоступны.', $html);
        self::assertStringContainsString('Оценка сайта в этой публикации недоступна.', $html);
        $data['report'] += ['reviewed_facts' => 5, 'confirmed_facts' => 3];
        $html = view('public-report.index', $data)->render();
        self::assertStringContainsString('Утверждений в снимке: 5; подтверждено: 3.', $html);
        self::assertStringNotContainsString('<span>Верно</span>', $html);
    }
}
