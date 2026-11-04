<?php

namespace App\Seller\Runtime;

final class ServicePhraseSelector
{
    public const DEFAULTS = [
        'error' => 'Секунду, уточню пару моментов и вернусь к вам сегодня.',
        'handoff' => 'Передаю менеджеру, он напишет сегодня до конца дня.',
        'out_of_hours' => 'Менеджер подключится в рабочее время.',
        'no_answer' => 'Не могу ответить на этот вопрос.',
        'do_not_disturb' => 'Спасибо, больше не будем вас беспокоить.',
        'reminder' => 'Здравствуйте! Возвращаюсь к нашему разговору.',
        'goodbye' => 'Спасибо за обращение! Хорошего дня.',
    ];

    private const EVENTS = [
        'error' => 'error',
        'handoff' => 'handoff',
        'out_of_hours' => 'out_of_hours',
        'unknown_fact' => 'no_answer',
        'opt_out' => 'do_not_disturb',
        'reminder' => 'reminder',
        'goodbye' => 'goodbye',
    ];

    public function forEvent(array $persona, string $event): ?string
    {
        $key = self::EVENTS[$event] ?? null;
        if ($key === null) {
            return null;
        }

        $phrase = $persona['service_phrases'][$key] ?? self::DEFAULTS[$key];

        return is_string($phrase) && trim($phrase) !== '' ? trim($phrase) : null;
    }
}
