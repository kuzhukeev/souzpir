<?php

namespace App\Seller\Evaluation;

use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;

/** A local preview of one policy turn, not a protected runtime conversation. */
final class DraftScenarioPreview
{
    public function turn(KnowledgeSnapshot $draft, string $client, array $expectations): array
    {
        $state = new LeadState;
        try {
            $goal = (new Policy($draft))->decide($state, ['client_intent' => 'unclear'], true);
            $examples = $draft->fewshot($goal->fewshotTags, $state->clientType(), 1);
            $answer = trim((string) ($examples[0]['text'] ?? 'Здравствуйте! Чем могу помочь?'));
            $guard = (new DeterministicGuard($draft))->check($answer, $goal, $state);
            $goalKind = $goal->kind;
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
                default => false,
            };
        }

        return ['client' => $client, 'agent' => $answer, 'meta' => ['expectation_checks' => $checks,
            'guard_verdict' => $guard['verdict'], 'guard_issues' => $guard['issues'], 'goal' => $goalKind,
            'draft_release_id' => $draft->releaseId(), 'preview_only' => true]];
    }
}
