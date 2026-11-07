<?php

namespace Tests\Feature\Seller\Knowledge;

use App\ProtectedOperations\DraftSimulation\FixtureRuntime;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The SP-E4-01R/S catalogs are only reachable through FixtureRuntime's judge fallback
 * (no persona-driven preview run today reproduces their exact wording — see results
 * SP-E4-01X), so the matching itself is exercised directly through reflection.
 */
final class DraftContextCatalogE401XTest extends TestCase
{
    public function test_matches_the_r_catalog_handoff_event_and_stays_unknown_without_it(): void
    {
        $match = $this->matchContextMethod();
        $runtime = app(FixtureRuntime::class);
        $events = [['type' => 'handoff', 'recipient' => 'руководитель']];

        self::assertTrue($match->invoke($runtime, 'big_order', 'escalate_big_order', [], 'Заказ на 700 тысяч.', 'Передал руководителю.', $events));
        self::assertNull($match->invoke($runtime, 'big_order', 'escalate_big_order', [], 'Заказ на 700 тысяч.', 'Передал руководителю.', []));
        $otherHistory = [['role' => 'client', 'text' => 'Другой контекст.']];
        self::assertNull($match->invoke($runtime, 'big_order', 'escalate_big_order', $otherHistory, 'Заказ на 700 тысяч.', 'Передал руководителю.', $events));
    }

    public function test_matches_the_s_catalog_full_card_on_conversation_end_only(): void
    {
        $match = $this->matchContextMethod();
        $runtime = app(FixtureRuntime::class);
        $history = [
            ['role' => 'client', 'text' => 'Воронеж'],
            ['role' => 'client', 'text' => 'на продажу'],
            ['role' => 'client', 'text' => 'нет'],
            ['role' => 'client', 'text' => 'павильон'],
            ['role' => 'client', 'text' => 'всё'],
            ['role' => 'client', 'text' => 'тысяч 100'],
            ['role' => 'client', 'text' => 'к ноябрю'],
            ['role' => 'client', 'text' => 'ivan.pav@mail.ru'],
        ];

        self::assertTrue($match->invoke($runtime, 'newbie_voronezh', 'full_card', $history, 'Спасибо', 'Спасибо за обращение.', [['type' => 'conversation_end']]));
        self::assertNull($match->invoke($runtime, 'newbie_voronezh', 'full_card', $history, 'Спасибо', 'Спасибо за обращение.', []));
    }

    private function matchContextMethod(): ReflectionMethod
    {
        $method = new ReflectionMethod(FixtureRuntime::class, 'matchContext');
        $method->setAccessible(true);

        return $method;
    }
}
