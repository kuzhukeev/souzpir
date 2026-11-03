<?php

namespace Tests\Feature\Seller\Runtime;

use App\Seller\Runtime\ServicePhraseSelector;
use PHPUnit\Framework\TestCase;

final class ServicePhraseSelectorTest extends TestCase
{
    public function test_saved_phrases_follow_runtime_events_and_defaults(): void
    {
        $selector = new ServicePhraseSelector;
        $persona = ['service_phrases' => ['no_answer' => 'Проверю факт и вернусь.']];

        self::assertSame('Проверю факт и вернусь.', $selector->forEvent($persona, 'unknown_fact'));
        self::assertSame(ServicePhraseSelector::DEFAULTS['handoff'], $selector->forEvent($persona, 'handoff'));
        self::assertSame(ServicePhraseSelector::DEFAULTS['do_not_disturb'], $selector->forEvent($persona, 'opt_out'));
        self::assertNull($selector->forEvent($persona, 'unrecognized'));
    }

    public function test_blank_saved_phrase_suppresses_event_text(): void
    {
        $selector = new ServicePhraseSelector;

        self::assertNull($selector->forEvent(['service_phrases' => ['error' => '  ']], 'error'));
        self::assertNull($selector->forEvent(['service_phrases' => ['error' => 42]], 'error'));
    }

    public function test_fake_dialogue_uses_the_saved_phrase_for_each_event(): void
    {
        $selector = new ServicePhraseSelector;
        $persona = ['service_phrases' => [
            'no_answer' => 'Уточню информацию.',
            'handoff' => 'Подключаю менеджера.',
            'goodbye' => 'До свидания.',
        ]];

        $replies = array_map(fn (string $event): ?string => $selector->forEvent($persona, $event),
            ['unknown_fact', 'handoff', 'goodbye']);

        self::assertSame(['Уточню информацию.', 'Подключаю менеджера.', 'До свидания.'], $replies);
    }
}
