<?php

namespace App\Seller\Evaluation;

use App\Ai\CanonicalJson;
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
                        $clients[] = ['text' => $entry['client'], 'field' => null];
                        break;
                    }
                }
            }
        }
        $expectations = is_array($persona['expect'] ?? null) ? $persona['expect'] : [];
        $state = new LeadState;
        $turns = [];
        foreach (array_slice($clients, 0, max(1, $maxTurns)) as $index => $client) {
            $seed = $personaKey !== null && $client['field'] !== null ? ['persona_key' => $personaKey, 'field' => $client['field']] : null;
            $turns[] = $this->previewTurn($draft, $client['text'], $expectations, $state, $index === 0, $request, $seed, $personaKey);
        }

        return $turns;
    }

    private function previewTurn(KnowledgeSnapshot $draft, string $client, array $expectations, LeadState $state, bool $first, ?Request $request = null, ?array $seed = null, ?string $personaKey = null): array
    {
        try {
            $policy = new Policy($draft);
            $extraction = $request ? app(FixtureRuntime::class)->extract($request, $draft, $client, $seed) : null;
            $extractionAvailable = $extraction !== null;
            $extraction ??= ['client_intent' => 'unclear'];
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
            'guard_verdict' => $guard['verdict'], 'guard_issues' => $guard['issues'], 'goal' => $goalKind,
            'draft_release_id' => $draft->releaseId(), 'preview_only' => true,
            'extraction_available' => $extractionAvailable ?? false, 'extracted_intent' => $extraction['client_intent'] ?? null,
            'extracted_slots' => $extraction['slot_updates'] ?? [], 'semantic_checks' => $semantic,
            'semantic_available' => $semantic !== null && ! in_array(null, $semantic, true)]];
    }
}
