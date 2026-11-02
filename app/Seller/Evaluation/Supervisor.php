<?php

namespace App\Seller\Evaluation;

use InvalidArgumentException;

final class Supervisor
{
    public const LAYERS = ['extractor', 'policy', 'responder', 'guard', 'knowledge'];

    // The donor defines 24 named issues and the fallback "other".
    public const ISSUE_TYPES = [
        'misunderstood_intent' => 'extractor', 'wrong_slot_value' => 'extractor', 'false_refusal' => 'extractor', 'missed_signal' => 'extractor',
        'wrong_next_question' => 'policy', 'repeated_question' => 'policy', 'ignored_client_question' => 'policy',
        'pushy_sales' => 'policy', 'missed_escalation' => 'policy', 'needless_escalation' => 'policy', 'no_followup' => 'policy',
        'unnatural' => 'responder', 'two_questions' => 'responder', 'too_long' => 'responder', 'missed_personal_hook' => 'responder',
        'argued' => 'responder', 'bad_objection_handling' => 'responder',
        'invented_fact' => 'guard', 'forbidden_promise' => 'guard', 'false_action_claim' => 'guard',
        'off_topic_fulfilled' => 'guard', 'lied_about_bot' => 'guard',
        'unknown_fact' => 'knowledge', 'stale_or_conflicting_fact' => 'knowledge', 'other' => 'responder',
    ];

    public const SCORES = ['naturalness', 'goal', 'facts', 'objections', 'brevity'];

    public function schema(): array
    {
        $score = ['type' => 'integer', 'minimum' => 1, 'maximum' => 5];
        $properties = array_fill_keys(self::SCORES, $score);
        $properties['comment'] = ['type' => 'string'];
        $properties['issues'] = ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['type', 'layer', 'severity', 'turn', 'quote', 'explanation', 'fix'], 'properties' => [
                'type' => ['type' => 'string', 'enum' => array_keys(self::ISSUE_TYPES)],
                'layer' => ['type' => 'string', 'enum' => self::LAYERS],
                'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'turn' => ['type' => 'integer', 'minimum' => 1], 'quote' => ['type' => 'string'],
                'explanation' => ['type' => 'string'], 'fix' => ['type' => 'string'],
            ]]];

        return ['type' => 'object', 'additionalProperties' => false,
            'required' => [...self::SCORES, 'comment', 'issues'], 'properties' => $properties];
    }

    public function transcript(array $turns): string
    {
        $lines = [];
        foreach ($turns as $index => $turn) {
            if (isset($turn['client'])) {
                $lines[] = 'Клиент: '.$turn['client'];
            }
            if (! empty($turn['agent'])) {
                $meta = $turn['meta'] ?? [];
                $service = [];
                if (! empty($meta['intent'])) {
                    $service[] = 'интент '.$meta['intent'].(isset($meta['confidence']) ? sprintf(' (%.2f)', $meta['confidence']) : '');
                }
                if (! empty($meta['goal'])) {
                    $service[] = 'цель '.$meta['goal'];
                }
                $service[] = 'tools: '.(! empty($meta['tools']) ? implode(', ', $meta['tools']) : '—');
                if (! empty($meta['guard'])) {
                    $service[] = 'guard '.$meta['guard'];
                }
                if (! empty($meta['error'])) {
                    $service[] = 'ОШИБКА LLM, запасная реплика';
                }
                $lines[] = '[ход '.($index + 1).': '.implode(' | ', $service).']';
                $lines[] = 'Антон: '.$turn['agent'];
            }
        }

        return implode("\n", $lines);
    }

    public function normalize(array $result, int $turnCount): array
    {
        $scores = [];
        foreach (self::SCORES as $key) {
            $value = $result[$key] ?? null;
            if (! is_int($value) || $value < 1 || $value > 5) {
                throw new InvalidArgumentException('Invalid judge score: '.$key);
            }
            $scores[$key] = $value;
        }
        if (! is_string($result['comment'] ?? null) || ! is_array($result['issues'] ?? null)) {
            throw new InvalidArgumentException('Invalid judge result.');
        }
        foreach ($result['issues'] as $issue) {
            if (! is_array($issue) || ! isset(self::ISSUE_TYPES[$issue['type'] ?? ''])
                || $issue['layer'] !== self::ISSUE_TYPES[$issue['type']] || ! in_array($issue['severity'] ?? null, ['low', 'medium', 'high'], true)
                || ! is_int($issue['turn'] ?? null) || $issue['turn'] < 1 || $issue['turn'] > $turnCount
                || ! is_string($issue['quote'] ?? null) || ! is_string($issue['explanation'] ?? null) || ! is_string($issue['fix'] ?? null)) {
                throw new InvalidArgumentException('Invalid judge issue.');
            }
        }

        return ['scores' => $scores, 'average' => round(array_sum($scores) / 5, 2),
            'issues' => array_values($result['issues']), 'comment' => $result['comment']];
    }

    public function aggregate(array $evaluations): array
    {
        $groups = [];
        $layers = array_fill_keys(self::LAYERS, 0);
        foreach ($evaluations as $index => $evaluation) {
            foreach ($evaluation['issues'] ?? [] as $issue) {
                $type = $issue['type'];
                $layer = self::ISSUE_TYPES[$type] ?? null;
                if ($layer === null) {
                    continue;
                }
                $layers[$layer]++;
                $groups[$type]['type'] = $type;
                $groups[$type]['layer'] = $layer;
                $groups[$type]['count'] = ($groups[$type]['count'] ?? 0) + 1;
                $groups[$type]['dialogs'][$evaluation['id'] ?? $index] = true;
            }
        }
        $groups = array_map(function (array $group): array {
            $group['dialogs'] = count($group['dialogs']);

            return $group;
        }, array_values($groups));
        usort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['dialogs' => count($evaluations), 'by_layer' => $layers, 'groups' => $groups];
    }
}
