<?php

namespace App\Vision\Profile;

final class AiCompetitorHints
{
    public function extract(array $snapshot): array
    {
        if (! is_string($snapshot['providers']['fake'] ?? null) || trim($snapshot['providers']['fake']) === '') {
            return [];
        }
        $allowed = [];
        foreach ($snapshot['metrics']['entities'] ?? [] as $entity) {
            if (is_array($entity) && ($entity['is_brand'] ?? null) === false && ($entity['relevance'] ?? 'confirmed') === 'confirmed' && is_string($entity['name'] ?? null)) {
                $allowed[$entity['name']] = true;
            }
        }
        $items = [];
        foreach ($snapshot['metrics']['slots'] ?? [] as $slot) {
            if (! is_array($slot) || ($slot['provider'] ?? null) !== 'fake' || ($slot['valid'] ?? false) !== true || ($slot['mentions_complete'] ?? false) !== true || ! is_int($slot['question_n'] ?? null) || $slot['question_n'] < 1) {
                continue;
            }
            foreach ($slot['mentions'] ?? [] as $mention) {
                $name = is_array($mention) ? ($mention['name'] ?? null) : null;
                if (! is_string($name) || ! isset($allowed[$name]) || trim($name) === '' || ($mention['ambiguous_brand'] ?? false) || in_array($name, array_column($items, 'value'), true)) {
                    continue;
                }
                $items[] = ['label' => 'Подтверждённый ответ fake AI', 'value' => mb_substr($name, 0, 180), 'source' => 'Подтверждённый замер, вопрос '.$slot['question_n'], 'kind' => 'ai_competitor'];
                if (count($items) >= 5) {
                    return $items;
                }
            }
        }

        return $items;
    }
}
