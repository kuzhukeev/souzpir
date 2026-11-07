<?php

namespace App\Seller\Evaluation;

final class ScenarioTranscript
{
    /** Extract the client inputs saved by the sandbox, without generating new turns. */
    public function clients(array $persona): array
    {
        if (($persona['custom'] ?? false) !== true || ! is_string($persona['behavior'] ?? null)) {
            return [];
        }
        preg_match_all('/(?:\A|\n\n)Клиент: (.*?)\nБот: /us', $persona['behavior'], $matches);

        return array_values(array_filter(array_map('trim', $matches[1] ?? []), fn ($text) => $text !== ''));
    }
}
