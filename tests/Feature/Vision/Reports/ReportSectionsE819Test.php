<?php

namespace Tests\Feature\Vision\Reports;

use App\Vision\Reports\ReportSections;
use Tests\TestCase;

final class ReportSectionsE819Test extends TestCase
{
    public function test_actions_include_only_saved_fields_and_up_to_three_steps(): void
    {
        $sections = app(ReportSections::class)->make(['recommendations' => ['items' => [['title' => 'Обновить сайт', 'why' => 'Поможет найти товары', 'priority' => 'Высокий', 'steps' => ['А', 'Б', 'В', 'Г']]]]]);
        self::assertSame('Высокий', $sections['actions'][0]['priority']);
        self::assertSame(['А', 'Б', 'В'], $sections['actions'][0]['steps']);
        self::assertSame([], app(ReportSections::class)->make(['recommendations' => ['items' => [['title' => 'Без шагов', 'steps' => 'ошибка']]]])['actions'][0]['steps']);
    }
}
