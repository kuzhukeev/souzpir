<?php

namespace App\ProtectedOperations\DraftSimulation;

/** Reviewed fake-only examples. Results describe simulated effects, never delivery. */
final class ReviewedSandboxFixtures
{
    public function forClient(string $client): ?array
    {
        return match ($client) {
            'Мне нужен человек, не бот.' => [
                'extract' => ['client_intent' => 'want_human', 'slot_updates' => [], 'wants_human' => true],
                'goal' => 'handoff', 'answer' => 'В тестовом сценарии запрос передан менеджеру.',
                'tool' => ['name' => 'escalate', 'arguments' => ['reason' => 'want_human'], 'status' => 'fake_completed'],
            ],
            'Пришлите реквизиты и договор.' => [
                'extract' => ['client_intent' => 'ask_requisites', 'slot_updates' => []],
                'goal' => 'answer_and_ask', 'answer' => 'В тестовом сценарии реквизиты отправлены.',
                'tool' => ['name' => 'send_requisites', 'arguments' => [], 'status' => 'fake_completed'],
            ],
            'Планирую заказ на 700 тысяч.' => [
                'extract' => ['client_intent' => 'unclear', 'slot_updates' => ['budget_range' => '700 тысяч']],
                'effect' => ['name' => 'notify_manager', 'status' => 'fake_completed'],
                'effect_answer' => 'В тестовом сценарии уведомление менеджеру передано.',
            ],
            default => null,
        };
    }
}
