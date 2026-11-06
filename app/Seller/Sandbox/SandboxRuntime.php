<?php

namespace App\Seller\Sandbox;

use App\Ai\Drivers\FakeDriver;
use App\Ai\Protected\DraftResponder;
use App\ProtectedOperations\DraftSimulation\FixtureRuntime;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;
use Illuminate\Http\Request;

/** Offline sandbox: policy and guard run against a snapshot, without executing tools. */
final class SandboxRuntime
{
    public function turn(Request $request, KnowledgeSnapshot $snapshot, string $text, array $stateData, array $history, bool $draft, ?string $clientName): array
    {
        $state = new LeadState($stateData);
        if ($history === [] && is_string($clientName) && trim($clientName) !== '') {
            $state->setSlot('name', trim($clientName));
        }
        $extraction = $draft ? app(FixtureRuntime::class)->extract($request, $snapshot, $text) : null;
        $source = $extraction === null ? 'default' : 'fixture';
        if ($extraction === null) {
            $fixtures = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-extractions.json')), true, 32, JSON_THROW_ON_ERROR);
            $extraction = $fixtures[$text] ?? ['client_intent' => 'unclear', 'slot_updates' => []];
            $source = isset($fixtures[$text]) ? 'fixture' : 'default';
        }
        $extraction['_text'] = $text;
        $policy = new Policy($snapshot);
        $policy->applyExtraction($state, $extraction);
        $goal = $policy->decide($state, $extraction, $history === []);
        $facts = [];
        foreach ($goal->facts as $group) {
            foreach ($group['facts'] ?? [] as $fact) {
                if (is_string($fact) && trim($fact) !== '') {
                    $facts[] = $fact;
                }
            }
        }
        $slots = [
            'city' => 'Из какого вы города?', 'purpose' => 'Для чего планируете покупку?',
            'experience' => 'У вас уже есть опыт продаж?', 'outlet_type' => 'Какой у вас формат точки?',
            'categories' => 'Какие категории вас интересуют?', 'current_supplier' => 'С кем вы сейчас работаете?',
            'budget_range' => 'Какой бюджет планируете?', 'timing' => 'Когда планируете покупку?',
            'email' => 'На какую почту можно отправить информацию?', 'messenger' => 'Как с вами удобнее связаться?',
            'next_contact_at' => 'Когда будет удобно продолжить?',
        ];
        $question = $slots[$goal->slot ?? ''] ?? 'Уточните, пожалуйста, что вас интересует?';
        $answer = trim(($facts[0] ?? '').' '.($goal->slot ? $question : ($facts === [] ? $question : '')));
        $responseSource = 'deterministic';
        try {
            $body = app(DraftResponder::class)->body($snapshot, $state, $goal, $text);
            $candidate = app(FakeDriver::class)->complete($body)['content'] ?? null;
            if (is_string($candidate) && trim($candidate) !== '' && mb_strlen($candidate) <= 5000) {
                $answer = trim($candidate);
                $responseSource = 'fixture';
            }
        } catch (\Throwable) {
            // Unknown inputs use the labelled deterministic fallback, never a live provider.
        }
        if ($history !== [] && preg_match('/^повтор(?:и|ите)[\s.!?]*$/ui', trim($text))) {
            $answer = end($history)['answer'];
            $responseSource = 'history';
        }
        if (in_array($goal->kind, ['handoff', 'silent', 'spam_silent', 'opt_out'], true)) {
            $answer = 'В тестовом режиме действия не выполняются.';
            $responseSource = 'deterministic';
        }
        $guard = (new DeterministicGuard($snapshot))->check($answer, $goal, $state);
        if (($guard['verdict'] ?? null) !== 'ok') {
            $answer = 'Тестовый ответ не прошёл проверку. Уточните запрос или измените знания.';
            $responseSource = 'guard_fallback';
        } else {
            $state->setFlag('introduced', true);
            if ($goal->slot) {
                $state->markAsked($goal->slot);
                if ($goal->slot === 'budget_range') {
                    $state->inc('budget_asks');
                }
            }
            if (isset($goal->trigger['id'])) {
                $state->useTrigger($goal->trigger['id']);
                $state->setSignal('last_trigger_turn', $state->counter('turns'));
            }
        }

        return ['answer' => $answer, 'state' => $state->toArray(), 'inspector' => [
            'intent' => $extraction['client_intent'] ?? 'unclear', 'extraction_source' => $source, 'response_source' => $responseSource, 'slot_updates' => $extraction['slot_updates'] ?? [],
            'goal' => $goal->toArray(), 'guard' => $guard, 'executed_tools' => [], 'previous_answer' => $history === [] ? null : end($history)['answer'],
        ]];
    }
}
