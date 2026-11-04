<?php

namespace App\Seller\Evaluation;

use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;

/** Deterministic draft preview using the seller policy and guard without paid calls. */
final class DraftScenarioPreview
{
    public function turn(KnowledgeSnapshot $draft, string $client, array $expectations): array
    {
        $state = new LeadState;

        return $this->previewTurn($draft, $client, $expectations, $state, true);
    }

    public function dialogue(KnowledgeSnapshot $draft, array $persona, int $maxTurns): array
    {
        $clients = [trim((string) ($persona['first_message'] ?? ''))];
        foreach (array_slice((array) ($persona['facts'] ?? []), 0, max(0, $maxTurns - 1)) as $fact) {
            if (is_scalar($fact) && trim((string) $fact) !== '') {
                $clients[] = 'Уточню: '.trim((string) $fact);
            }
        }
        if (count($clients) === 1 && is_string($persona['behavior'] ?? null) && trim($persona['behavior']) !== '') {
            $clients[] = trim($persona['behavior']);
        }
        $expectations = is_array($persona['expect'] ?? null) ? $persona['expect'] : [];
        $state = new LeadState;
        $turns = [];
        foreach (array_slice($clients, 0, max(1, $maxTurns)) as $index => $client) {
            $turns[] = $this->previewTurn($draft, $client ?: 'Тестовый вопрос', $expectations, $state, $index === 0);
        }

        return $turns;
    }

    private function previewTurn(KnowledgeSnapshot $draft, string $client, array $expectations, LeadState $state, bool $first): array
    {
        try {
            $policy = new Policy($draft);
            $extraction = ['client_intent' => 'unclear'];
            $policy->applyExtraction($state, $extraction);
            $goal = $policy->decide($state, $extraction, $first);
            $examples = $draft->fewshot($goal->fewshotTags, $state->clientType(), 1);
            $answer = trim((string) ($examples[0]['text'] ?? 'Здравствуйте! Чем могу помочь?'));
            $guard = (new DeterministicGuard($draft))->check($answer, $goal, $state);
            $goalKind = $goal->kind;
            $state->setFlag('introduced', true);
        } catch (\Throwable) {
            $answer = '';
            $guard = ['verdict' => 'unavailable', 'issues' => ['Недостаточно данных черновика для проверки.']];
            $goalKind = null;
        }
        $checks = [];
        foreach ($expectations as $expectation) {
            if (! is_string($expectation)) {
                continue;
            }
            $checks[$expectation] = match ($expectation) {
                'one_question_per_turn' => mb_substr_count($answer, '?') <= 1,
                'no_deferred_promise' => ! preg_match('/(позже|перезвон|напишу вам)/ui', $answer),
                'no_html' => ! preg_match('/<[^>]+>/', $answer),
                default => false,
            };
        }

        return ['client' => $client, 'agent' => $answer, 'meta' => ['expectation_checks' => $checks,
            'guard_verdict' => $guard['verdict'], 'guard_issues' => $guard['issues'], 'goal' => $goalKind,
            'draft_release_id' => $draft->releaseId(), 'preview_only' => true]];
    }
}
