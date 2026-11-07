<?php

namespace App\Seller\Sandbox;

use App\Ai\Drivers\FakeDriver;
use App\Ai\Protected\DraftResponder;
use App\ProtectedOperations\DraftSimulation\FixtureRuntime;
use App\ProtectedOperations\DraftSimulation\ReviewedSandboxFixtures;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Engine\ToolArguments;
use App\Seller\Knowledge\KnowledgeSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Offline sandbox: policy, guard and fixture-backed effects run without live delivery. */
final class SandboxRuntime
{
    public function turn(Request $request, KnowledgeSnapshot $snapshot, string $text, array $stateData, array $history, bool $draft, ?string $clientName): array
    {
        $state = new LeadState($stateData);
        if ($history === [] && is_string($clientName) && trim($clientName) !== '') {
            $state->setSlot('name', trim($clientName));
        }
        $extraction = $draft ? app(FixtureRuntime::class)->extract($request, $snapshot, $text) : app(FixtureRuntime::class)->extractPublished($snapshot, $text);
        $source = $extraction === null ? 'default' : 'fixture';
        if ($extraction === null) {
            $fixtures = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-extractions.json')), true, 32, JSON_THROW_ON_ERROR);
            $extraction = $fixtures[$text] ?? ['client_intent' => 'unclear', 'slot_updates' => []];
            $source = isset($fixtures[$text]) ? 'fixture' : 'default';
        }
        $extraction['_text'] = $text;
        $previousNotification = $state->signal('notify_manager');
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
        // Only conditions explicitly referenced by the selected facts are shown.
        $conditionKeys = [];
        foreach ($goal->facts as $selectedGroup) {
            foreach ($snapshot->all('facts') as $rawGroup) {
                if (($rawGroup['id'] ?? null) !== ($selectedGroup['id'] ?? null)) {
                    continue;
                }
                $rawByResolved = [];
                foreach ($rawGroup['facts'] ?? [] as $rawFact) {
                    if (is_string($rawFact)) {
                        $rawByResolved[$snapshot->resolvePlaceholders($rawFact)][] = $rawFact;
                    }
                }
                foreach ($selectedGroup['facts'] ?? [] as $selectedFact) {
                    $matches = array_unique($rawByResolved[$selectedFact] ?? []);
                    if (count($matches) !== 1) {
                        continue;
                    }
                    preg_match_all('/\{cond:([a-zA-Z0-9_.]+)\}/u', reset($matches), $references);
                    foreach ($references[1] as $key) {
                        if (Arr::has($snapshot->conditions(), $key)) {
                            $conditionKeys[$key] = data_get($snapshot->conditions(), $key);
                        }
                    }
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
        $toolCalls = [];
        $reviewed = app(ReviewedSandboxFixtures::class)->forClient($text);
        try {
            $body = app(DraftResponder::class)->body($snapshot, $state, $goal, $text);
            $response = app(FakeDriver::class)->complete($body);
            $candidate = $response['content'] ?? null;
            $toolCalls = is_array($response['toolCalls'] ?? null) ? $response['toolCalls'] : [];
            if (is_string($candidate) && trim($candidate) !== '' && mb_strlen($candidate) <= 5000) {
                $answer = trim($candidate);
                $responseSource = 'fixture';
            }
        } catch (\Throwable) {
            // Reviewed examples are bound to the current policy goal; unknown inputs stay on fallback.
            if (is_array($reviewed) && ($reviewed['goal'] ?? null) === $goal->kind && is_array($reviewed['tool'] ?? null)) {
                $candidate = $reviewed['answer'];
                $toolCalls = [$reviewed['tool']];
                $answer = $candidate;
                $responseSource = 'reviewed_fixture';
            }
        }
        if ($history !== [] && preg_match('/^повтор(?:и|ите)[\s.!?]*$/ui', trim($text))) {
            $previous = end($history)['answer'] ?? null;
            if (is_string($previous) && trim($previous) !== '') {
                $answer = $previous;
                $responseSource = 'history';
                $toolCalls = [];
            }
        }
        $tools = [];
        foreach ($toolCalls as $call) {
            $name = $call['name'] ?? null;
            $arguments = $call['arguments'] ?? null;
            if (! is_string($name) || ! in_array($name, $goal->allowedTools, true) || ! is_array($arguments)
                || ! (new ToolArguments)->check($name, $arguments)['valid']) {
                continue;
            }
            $completed = is_array($reviewed) && ($reviewed['goal'] ?? null) === $goal->kind
                && ($reviewed['tool']['name'] ?? null) === $name && ($reviewed['tool']['arguments'] ?? null) === $arguments
                && ($reviewed['tool']['status'] ?? null) === 'fake_completed';
            if (! $completed) {
                try {
                    $toolBody = app(FixtureRuntime::class)->body($snapshot, 'tool', ['name' => $name, 'arguments' => $arguments]);
                    $toolResponse = app(FakeDriver::class)->complete($toolBody);
                    $toolResult = json_decode($toolResponse['content'] ?? '', true, 32, JSON_THROW_ON_ERROR);
                    $completed = is_array($toolResult) && ($toolResult['name'] ?? null) === $name && ($toolResult['status'] ?? null) === 'fake_completed';
                } catch (\Throwable) {
                    $completed = false;
                }
            }
            if ($completed) {
                $tools[] = ['name' => $name, 'status' => 'fake_completed'];
            }
        }
        if ($previousNotification !== 'big_order' && $state->signal('notify_manager') === 'big_order'
            && ($reviewed['effect']['name'] ?? null) === 'notify_manager'
            && ($reviewed['effect']['status'] ?? null) === 'fake_completed') {
            $tools[] = $reviewed['effect'];
            $answer = $reviewed['effect_answer'];
            $responseSource = 'reviewed_fixture';
        }
        if ($tools === [] && $responseSource === 'reviewed_fixture') {
            $answer = 'В тестовом сценарии действие не выполнено.';
            $responseSource = 'tool_fallback';
        }
        if ($tools === [] && preg_match('/(?<!\pL)(?:переда(?:м|ю|л[аи]?|но)|отправ(?:лю|ил[аи]?|лено)|высл(?:ал[аи]?|ано|ю)|направ(?:лю|ил[аи]?|лено)|свяж(?:ется|усь)|переключ(?:у|ил[аи]?|ено)|перезвон(?:ю|ит))(?!\pL)/iu', $answer)) {
            $answer = 'В тестовом сценарии действие не выполнено.';
            $responseSource = 'tool_fallback';
        }
        if (($goal->kind === 'handoff' && $tools === []) || in_array($goal->kind, ['silent', 'spam_silent', 'opt_out'], true)) {
            $answer = 'В тестовом режиме действия не выполняются.';
            $responseSource = 'deterministic';
        }
        $guard = (new DeterministicGuard($snapshot))->check($answer, $goal, $state, ['tools_called' => array_column($tools, 'name')]);
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
            'goal' => $goal->toArray(), 'selected_facts' => $facts, 'selected_conditions' => $conditionKeys, 'guard' => $guard, 'executed_tools' => $tools, 'previous_answer' => $history === [] ? null : end($history)['answer'],
        ]];
    }
}
