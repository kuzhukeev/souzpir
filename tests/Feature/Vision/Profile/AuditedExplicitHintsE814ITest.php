<?php

namespace Tests\Feature\Vision\Profile;

use App\Vision\Profile\AuditedExplicitHints;
use PHPUnit\Framework\TestCase;

final class AuditedExplicitHintsE814ITest extends TestCase
{
    public function test_only_explicit_headings_from_successful_same_host_pages_are_returned(): void
    {
        $audit = ['pages' => [
            ['url' => 'https://example.com/catalog', 'status' => 200, 'headings' => ['Типы покупателей: Магазины; Рестораны', 'Конкуренты: Сосед', 'Вопросы клиентов: Есть доставка?; Где купить?; Не вопрос']],
            ['url' => 'https://foreign.example/', 'status' => 200, 'headings' => ['Конкуренты: Чужой']],
            ['url' => 'https://example.com/error', 'status' => 500, 'headings' => ['Конкуренты: Ошибка']],
            ['url' => 'https://example.com/other', 'status' => 200, 'headings' => ['Конкуренты: Сосед']],
        ]];
        $reader = new AuditedExplicitHints;
        $types = $reader->extract($audit, 'https://example.com', 3);
        self::assertSame(['Магазины', 'Рестораны'], array_column($types, 'value'));
        self::assertSame('customer_type', $types[0]['kind']);
        self::assertSame(['Сосед'], array_column($reader->extract($audit, 'https://example.com', 4), 'value'));
        self::assertSame(['Есть доставка?', 'Где купить?'], array_column($reader->extract($audit, 'https://example.com', 7), 'value'));
        self::assertSame([], $reader->extract($audit, 'not a URL', 4));
        self::assertSame([], $reader->extract($audit, 'https://example.com', 5));
    }
}
