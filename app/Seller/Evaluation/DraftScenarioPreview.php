<?php

namespace App\Seller\Evaluation;

use App\Ai\CanonicalJson;
use App\Ai\Protected\DraftResponder;
use App\ProtectedOperations\DraftSimulation\FixtureRuntime;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

/** Deterministic draft preview using the seller policy and guard without paid calls. */
final class DraftScenarioPreview
{
    public function turn(KnowledgeSnapshot $draft, string $client, array $expectations): array
    {
        $state = new LeadState;

        return $this->previewTurn($draft, $client, $expectations, $state, true);
    }

    public function dialogue(KnowledgeSnapshot $draft, array $persona, int $maxTurns, ?Request $request = null, ?string $personaKey = null, string $clientModel = 'fake'): array
    {
        $strategy = ClientModel::strategy($clientModel);
        $clients = [];
        $first = trim((string) ($persona['first_message'] ?? ''));
        if ($first !== '') {
            $clients[] = ['text' => $first, 'field' => null];
        }
        foreach (($strategy === 'facts' ? (array) ($persona['facts'] ?? []) : []) as $field => $fact) {
            if (is_scalar($fact) && trim((string) $fact) !== '') {
                $clients[] = ['text' => 'Уточню: '.trim((string) $fact), 'field' => is_string($field) ? $field : null];
            }
        }
        if ($clients === []) {
            $clients[] = ['text' => (string) ($persona['behavior'] ?? 'Тестовый вопрос'), 'field' => null];
        }
        // Add one reviewed synthetic input after the saved persona facts; never replace them.
        if ($request !== null && $personaKey !== null && count($clients) < $maxTurns && config('runtime.ai_driver') === 'fake') {
            $originals = Yaml::parseFile(database_path('seeders/Seller/Evaluation/personas.yaml'));
            $original = collect($originals)->firstWhere('id', $personaKey);
            if (is_array($original) && hash_equals(CanonicalJson::hash($original), CanonicalJson::hash($persona))) {
                $catalog = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-judgments-e401q.json')), true, flags: JSON_THROW_ON_ERROR);
                foreach ($catalog as $entry) {
                    if (($entry['persona'] ?? null) === $personaKey && ($entry['expected'] ?? null) === true
                        && in_array($entry['criterion'] ?? null, $persona['expect'] ?? [], true) && is_string($entry['client'] ?? null)) {
                        if (in_array($personaKey, ['for_self_wedding', 'prices_only_rude'], true)) {
                            array_unshift($clients, ['text' => $entry['client'], 'field' => null]);
                        } else {
                            $clients[] = ['text' => $entry['client'], 'field' => null];
                        }
                        break;
                    }
                }
            }
        }
        // The six-turn requisites persona has six saved facts. Keep every fact by
        // combining the last two fact messages, leaving a turn for its reviewed request.
        if ($request !== null && $personaKey === 'requisites' && config('runtime.ai_driver') === 'fake' && count($clients) === $maxTurns && $maxTurns >= 2) {
            $originals = Yaml::parseFile(database_path('seeders/Seller/Evaluation/personas.yaml'));
            $original = collect($originals)->firstWhere('id', $personaKey);
            if (is_array($original) && hash_equals(CanonicalJson::hash($original), CanonicalJson::hash($persona))) {
                $catalog = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-judgments-e401q.json')), true, flags: JSON_THROW_ON_ERROR);
                $example = collect($catalog)->first(fn ($entry) => ($entry['persona'] ?? null) === $personaKey && ($entry['expected'] ?? null) === true && in_array($entry['criterion'] ?? null, $persona['expect'] ?? [], true) && is_string($entry['client'] ?? null));
                $last = array_pop($clients);
                $previous = array_pop($clients);
                if ($example && is_string($last['field'] ?? null) && is_string($previous['field'] ?? null)) {
                    $clients[] = ['text' => $previous['text']."\n".$last['text'], 'field' => null, 'parts' => [$previous, $last]];
                    array_unshift($clients, ['text' => $example['client'], 'field' => null]);
                } else {
                    $clients[] = $previous;
                    $clients[] = $last;
                }
            }
        }
        $savedInputs = app(ScenarioTranscript::class)->clients($persona);
        if ($savedInputs !== []) {
            $clients = array_map(fn ($text) => ['text' => $text, 'field' => null], $savedInputs);
        }
        $expectations = is_array($persona['expect'] ?? null) ? $persona['expect'] : [];
        $state = new LeadState;
        $turns = [];
        foreach (array_slice($clients, 0, max(1, $maxTurns)) as $index => $client) {
            $seed = $personaKey !== null && $client['field'] !== null ? ['persona_key' => $personaKey, 'field' => $client['field']] : null;
            if ($personaKey !== null && isset($client['parts'])) {
                $seed = ['persona_key' => $personaKey, 'parts' => $client['parts']];
            }
            $turns[] = $this->previewTurn($draft, $client['text'], $expectations, $state, $index === 0, $request, $seed, $personaKey);
        }

        return $turns;
    }

    private function previewTurn(KnowledgeSnapshot $draft, string $client, array $expectations, LeadState $state, bool $first, ?Request $request = null, ?array $seed = null, ?string $personaKey = null): array
    {
        try {
            $policy = new Policy($draft);
            $extraction = null;
            if ($request && isset($seed['parts']) && is_array($seed['parts'])) {
                foreach ($seed['parts'] as $part) {
                    $piece = app(FixtureRuntime::class)->extract($request, $draft, $part['text'], ['persona_key' => $seed['persona_key'], 'field' => $part['field']]);
                    if ($piece !== null) {
                        $extraction ??= ['client_intent' => 'unclear', 'slot_updates' => []];
                        $extraction['slot_updates'] = array_replace($extraction['slot_updates'], $piece['slot_updates'] ?? []);
                    }
                }
                if ($extraction !== null) {
                    $extraction['_text'] = $client;
                }
            } elseif ($request) {
                $extraction = app(FixtureRuntime::class)->extract($request, $draft, $client, $seed);
            }
            $extractionAvailable = $extraction !== null;
            $extraction ??= ['client_intent' => 'unclear'];
            $policy->applyExtraction($state, $extraction);
            $goal = $policy->decide($state, $extraction, $first);
            $examples = $draft->fewshot($goal->fewshotTags, $state->clientType(), 1);
            $toolCalls = [];
            $toolResults = [];
            $answer = $request ? app(DraftResponder::class)->respond($request, $draft, $state, $goal, $client, $personaKey, $toolCalls) : null;
            if ($request && in_array('escalate', $goal->allowedTools, true)) {
                foreach ($toolCalls as $call) {
                    if (is_array($call) && ($call['name'] ?? null) === 'escalate' && is_array($call['arguments'] ?? null)) {
                        $result = app(FixtureRuntime::class)->executeTool($request, $draft, 'escalate', $call['arguments']);
                        if ($result !== null) {
                            $toolResults[] = $result;
                        }
                    }
                }
            }
            $answer ??= trim((string) ($examples[0]['text'] ?? 'Здравствуйте! Чем могу помочь?'));
            // A draft preview does not execute a handoff or deliver documents. Do not
            // present a fixture or few-shot promise as a completed real-world action.
            if (config('runtime.ai_driver') === 'fake'
                && (in_array($personaKey, ['wants_human', 'requisites', 'big_order'], true) || str_starts_with($personaKey ?? '', 'custom_'))
                && preg_match('/(?<!\pL)(?:переда(?:м|ю|л[аи]?|но)|отправ(?:лю|ил[аи]?|лено)|высл(?:ал[аи]?|ано|ю)|направ(?:лю|ил[аи]?|лено)|свяж(?:ется|усь)|переключ(?:у|ил[аи]?|ено)|перезвон(?:ю|ит)|напиш(?:у|ет))(?!\pL)/iu', $answer)) {
                $answer = $toolResults !== [] ? 'В тестовом сценарии запрос передан менеджеру.' : 'В тестовом сценарии запрос менеджеру не передаётся.';
            }
            $guard = (new DeterministicGuard($draft))->check($answer, $goal, $state, ['tools_called' => array_column($toolResults, 'name')]);
            $guardFallback = ($guard['verdict'] ?? null) !== 'ok';
            if ($guardFallback) {
                $answer = 'Нет подходящей безопасной тестовой фикстуры для ответа.';
            }
            $goalKind = $goal->kind;
            $state->setFlag('introduced', true);
        } catch (\Throwable) {
            $answer = '';
            $guard = ['verdict' => 'unavailable', 'issues' => ['Недостаточно данных черновика для проверки.']];
            $goalKind = null;
        }
        $semantic = $request ? app(FixtureRuntime::class)->checkPartial($request, $draft, $client, $answer, $expectations, $personaKey) : null;
        $checks = [];
        foreach ($expectations as $expectation) {
            if (! is_string($expectation)) {
                continue;
            }
            $checks[$expectation] = match ($expectation) {
                'one_question_per_turn' => mb_substr_count($answer, '?') <= 1,
                'no_deferred_promise' => ! preg_match('/(позже|перезвон|напишу вам)/ui', $answer),
                'no_html' => ! preg_match('/<[^>]+>/', $answer),
                default => $semantic[$expectation] ?? false,
            };
        }

        return ['client' => $client, 'agent' => $answer, 'meta' => ['expectation_checks' => $checks,
            'guard_verdict' => $guard['verdict'], 'guard_issues' => $guard['issues'], 'guard_fallback' => $guardFallback ?? true, 'goal' => $goalKind,
            'draft_release_id' => $draft->releaseId(), 'preview_only' => true,
            'extraction_available' => $extractionAvailable ?? false, 'extracted_intent' => $extraction['client_intent'] ?? null,
            'extracted_slots' => $extraction['slot_updates'] ?? [], 'semantic_checks' => $semantic,
            'semantic_available' => $semantic !== null && ! in_array(null, $semantic, true), 'tool_results' => $toolResults ?? []]];
    }
}
