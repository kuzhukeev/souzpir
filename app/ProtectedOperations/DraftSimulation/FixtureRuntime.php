<?php

namespace App\ProtectedOperations\DraftSimulation;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\FakeDriver;
use App\Models\User;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\ToolArguments;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/** Fixture-only draft runtime bound to an authorized session and immutable draft. */
final class FixtureRuntime
{
    public function extract(Request $request, KnowledgeSnapshot $draft, string $client, ?array $seed = null): ?array
    {
        $data = $this->run($request, $draft, 'extract', ['client' => $client, 'intents' => $draft->intentIds()], $seed);

        return $this->validateExtraction($data, $draft, $client);
    }

    /** The caller must already have selected a tenant-scoped published snapshot. */
    public function extractPublished(KnowledgeSnapshot $snapshot, string $client): ?array
    {
        if (config('runtime.ai_driver') !== 'fake') {
            return null;
        }
        try {
            $result = app(FakeDriver::class)->complete($this->body($snapshot, 'extract', ['client' => $client, 'intents' => $snapshot->intentIds()]));
            $data = json_decode($result['content'] ?? '', true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/seeded-extractions.json'), true);
            $data = (is_array($fixtures) ? ($fixtures[$client] ?? null) : null) ?? (app(ReviewedSandboxFixtures::class)->forClient($client)['extract'] ?? null);
        }

        return $this->validateExtraction($data, $snapshot, $client);
    }

    private function validateExtraction(mixed $data, KnowledgeSnapshot $draft, string $client): ?array
    {
        if (! is_array($data) || ! is_string($data['client_intent'] ?? null) || ! in_array($data['client_intent'], [...$draft->intentIds(), 'unclear'], true)) {
            return null;
        }

        $publishedFields = collect($draft->policy()['client_fields'] ?? [])
            ->filter(fn ($field) => is_array($field) && is_string($field['id'] ?? null) && trim($field['id']) !== '')
            ->keyBy('id');

        $slots = $data['slot_updates'] ?? [];
        if (! is_array($slots) || array_is_list($slots) && $slots !== [] || array_diff(array_keys($slots), [...LeadState::SLOTS, ...$publishedFields->keys()->all()])) {
            return null;
        }
        foreach ($slots as $key => $value) {
            $field = $publishedFields->get($key);
            if (in_array($key, ['categories', 'brands_interest'], true)) {
                if ($value !== null && (! is_array($value) || ! array_is_list($value) || count(array_filter($value, 'is_string')) !== count($value))) {
                    return null;
                }
            } elseif ($field !== null && ($field['type'] ?? 'text') === 'select' && ($field['options'] ?? []) !== []) {
                if ($value !== null && ! in_array($value, $field['options'], true)) {
                    return null;
                }
            } elseif ($value !== null && ! is_string($value)) {
                return null;
            }
        }
        $result = ['client_intent' => $data['client_intent'], 'slot_updates' => $slots, '_text' => $client];
        foreach (['wants_only_prices', 'asked_if_bot', 'wants_human', 'do_not_disturb', 'off_topic', 'prompt_injection'] as $field) {
            if (array_key_exists($field, $data)) {
                if (! is_bool($data[$field])) {
                    return null;
                }
                $result[$field] = $data[$field];
            }
        }
        foreach (['client_questions', 'client_claims', 'refused_slots', 'objections'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (! is_array($value) || ! array_is_list($value) || count(array_filter($value, 'is_string')) !== count($value)
                    || ($field === 'refused_slots' && array_diff($value, [...LeadState::SLOTS, ...$publishedFields->keys()->all()]))
                    || ($field === 'objections' && array_diff($value, Extractor::OBJECTIONS))) {
                    return null;
                }
                $result[$field] = $value;
            }
        }
        if (isset($data['sentiment'])) {
            if (! in_array($data['sentiment'], ['positive', 'neutral', 'negative'], true)) {
                return null;
            }
            $result['sentiment'] = $data['sentiment'];
        }
        foreach (['unknown_question', 'closed_topic'] as $field) {
            if (isset($data[$field])) {
                if (! is_string($data[$field]) || ($field === 'closed_topic' && ! in_array($data[$field], array_column($draft->closedTopics(), 'id'), true))) {
                    return null;
                }
                $result[$field] = $data[$field];
            }
        }

        return $result;
    }

    /** Execute only an explicitly recorded fake tool response; never contact a real channel. */
    public function executeTool(Request $request, KnowledgeSnapshot $draft, string $name, array $arguments, ?string $client = null): ?array
    {
        if (config('runtime.ai_driver') !== 'fake' || ! in_array($name, ['escalate', 'send_requisites'], true) || ! (new ToolArguments)->check($name, $arguments)['valid']) {
            return null;
        }
        $result = $this->run($request, $draft, 'tool', ['name' => $name, 'arguments' => $arguments]);
        $reviewed = $client === null ? null : app(ReviewedSandboxFixtures::class)->forClient($client);
        if ($result === null && ($reviewed['tool']['name'] ?? null) === $name && ($reviewed['tool']['arguments'] ?? null) === $arguments) {
            $result = $reviewed['tool'];
        }
        if (! is_array($result) || ($result['status'] ?? null) !== 'fake_completed' || ($result['name'] ?? null) !== $name) {
            return null;
        }

        return ['name' => $name, 'status' => 'fake_completed'];
    }

    /** Preserve the strict legacy API for callers which require every criterion. */
    public function check(Request $request, KnowledgeSnapshot $draft, string $client, string $answer, array $expectations, ?string $personaKey = null, array $history = [], array $events = []): ?array
    {
        $checks = $this->checkPartial($request, $draft, $client, $answer, $expectations, $personaKey, $history, $events);
        if ($checks === null || in_array(null, $checks, true)) {
            return null;
        }

        return $checks;
    }

    /** A null entry means there is no verified evidence for that criterion. */
    public function checkPartial(Request $request, KnowledgeSnapshot $draft, string $client, string $answer, array $expectations, ?string $personaKey = null, array $history = [], array $events = []): ?array
    {
        if (count(array_filter($expectations, 'is_string')) !== count($expectations)) {
            return null;
        }
        $data = $this->run($request, $draft, 'judge', ['client' => $client, 'answer' => $answer, 'expectations' => array_values($expectations)], personaKey: $personaKey, history: $history, events: $events);
        if (! is_array($data) || ! is_array($data['checks'] ?? null)) {
            return null;
        }
        $checks = [];
        foreach ($expectations as $expectation) {
            if (! is_string($expectation)) {
                return null;
            }
            $value = $data['checks'][$expectation] ?? null;
            if ($value !== null && ! is_bool($value)) {
                return null;
            }
            $checks[$expectation] = $value;
        }

        return $checks;
    }

    public function body(KnowledgeSnapshot $draft, string $stage, array $input): array
    {
        return ['model' => 'fake-draft-'.$stage, 'draft' => $draft->releaseId(), 'checksum' => $draft->checksum(), 'input' => $input];
    }

    private function run(Request $request, KnowledgeSnapshot $draft, string $stage, array $input, ?array $seed = null, ?string $personaKey = null, array $history = [], array $events = []): ?array
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($draft, $stage, $input, $seed, $personaKey, $history, $events): ?array {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            $exists = DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('id', $draft->releaseId())->where('status', 'draft')->exists();
            abort_unless($exists, 404);
            $current = app(KnowledgeRepository::class)->forRelease($tenant, $draft->releaseId(), true);
            if (! hash_equals($current->checksum(), $draft->checksum()) || $current->toArray() !== $draft->toArray()) {
                throw new LogicException('Draft changed during preview.');
            }
            if (config('runtime.ai_driver') !== 'fake') {
                return null;
            }
            try {
                $result = app(FakeDriver::class)->complete($this->body($draft, $stage, $input));
                $value = json_decode($result['content'] ?? '', true, 32, JSON_THROW_ON_ERROR);

                return is_array($value) ? $value : null;
            } catch (Throwable) {
                if ($stage === 'judge') {
                    $checks = [];
                    foreach ($input['expectations'] as $expectation) {
                        $answer = $input['answer'];
                        $checked = match ($expectation) {
                            'one_question_per_turn' => mb_substr_count($answer, '?') <= 1,
                            'no_html' => ! preg_match('/<[^>]+>/', $answer),
                            default => $this->reviewedPair($tenant, $personaKey, $input['client'], $answer, $expectation, $history, $events),
                        };
                        $checks[$expectation] = $checked;
                    }

                    return ['checks' => $checks];
                }
                if ($stage !== 'extract') {
                    return null;
                }
                if (is_array($seed) && is_string($seed['persona_key'] ?? null) && is_string($seed['field'] ?? null)) {
                    $catalog = json_decode(file_get_contents(__DIR__.'/fixtures/seeded-personas.json'), true);
                    $entry = $catalog[$seed['persona_key']][$seed['field']] ?? null;
                    $stored = DB::table('seller_personas')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                        ->where('key', $seed['persona_key'])->value('payload');
                    $persona = is_string($stored) ? json_decode($stored, true) : null;
                    if (is_array($entry) && is_string($entry['value'] ?? null) && is_string($entry['slot'] ?? null)
                        && data_get($persona, 'facts.'.$seed['field']) === $entry['value']
                        && $input['client'] === 'Уточню: '.$entry['value']) {
                        $value = $entry['slot'] === 'categories' ? [$entry['value']] : $entry['value'];

                        return ['client_intent' => 'unclear', 'slot_updates' => [$entry['slot'] => $value]];
                    }
                }
                if ($seed !== null) {
                    return null;
                }
                $fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/seeded-extractions.json'), true);

                return (is_array($fixtures) ? ($fixtures[$input['client']] ?? null) : null) ?? (app(ReviewedSandboxFixtures::class)->forClient($input['client'])['extract'] ?? null);
            }
        });
    }

    private function reviewedPair(TenantContext $tenant, ?string $personaKey, string $client, string $answer, string $criterion, array $history = [], array $events = []): ?bool
    {
        if ($personaKey === null) {
            return null;
        }
        $stored = DB::table('seller_personas')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->where('key', $personaKey)->value('payload');
        $persona = is_string($stored) ? json_decode($stored, true) : null;
        $originals = Yaml::parseFile(database_path('seeders/Seller/Evaluation/personas.yaml'));
        $original = collect($originals)->firstWhere('id', $personaKey);
        if (! is_array($persona) || ! is_array($original) || ! hash_equals(CanonicalJson::hash($original), CanonicalJson::hash($persona))
            || ! in_array($criterion, $persona['expect'] ?? [], true)) {
            return null;
        }
        $examples = json_decode(file_get_contents(__DIR__.'/fixtures/seeded-judgments-e401q.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($examples as $example) {
            if ($example['persona'] === $personaKey && $example['criterion'] === $criterion
                && $example['client'] === $client && $example['answer'] === $answer && is_bool($example['expected'])) {
                return $example['expected'];
            }
        }

        return $this->matchContext($personaKey, $criterion, $history, $client, $answer, $events);
    }

    /**
     * Match the SP-E4-01R/S reviewed context catalogs against the full accumulated
     * history and events of this preview, not only the current client/agent pair.
     * The catalog's own `expected` value is returned verbatim, including `null`
     * for a context the reviewers recorded as not provably verifiable.
     */
    private function matchContext(string $personaKey, string $criterion, array $history, string $client, string $answer, array $events): ?bool
    {
        $historySignature = $this->turnSignature($history);
        $eventsSignature = $this->eventSignature($events);
        foreach ($this->contextCatalog() as $entry) {
            if (! is_array($entry) || ($entry['persona'] ?? null) !== $personaKey || ($entry['criterion'] ?? null) !== $criterion) {
                continue;
            }
            if ($this->turnSignature(is_array($entry['history'] ?? null) ? $entry['history'] : []) !== $historySignature) {
                continue;
            }
            if (array_key_exists('client', $entry) && $entry['client'] !== $client) {
                continue;
            }
            if (array_key_exists('answer', $entry) && $entry['answer'] !== $answer) {
                continue;
            }
            if ($this->eventSignature(is_array($entry['events'] ?? null) ? $entry['events'] : []) !== $eventsSignature) {
                continue;
            }

            return $entry['expected'];
        }

        return null;
    }

    private function contextCatalog(): array
    {
        static $catalog = null;
        if ($catalog === null) {
            $catalog = [];
            foreach (['seeded-context-e401r.json', 'seeded-context-e401s.json', 'seeded-context-e401s-remaining.json'] as $file) {
                $catalog = [...$catalog, ...json_decode(file_get_contents(__DIR__.'/fixtures/'.$file), true, 512, JSON_THROW_ON_ERROR)];
            }
        }

        return $catalog;
    }

    private function turnSignature(array $turns): array
    {
        return array_map(fn ($turn) => [$turn['role'] ?? null, $turn['text'] ?? null], $turns);
    }

    private function eventSignature(array $events): array
    {
        $signatures = array_map(function ($event) {
            if (! is_array($event)) {
                return null;
            }
            ksort($event);

            return json_encode($event, JSON_THROW_ON_ERROR);
        }, $events);
        sort($signatures);

        return $signatures;
    }
}
