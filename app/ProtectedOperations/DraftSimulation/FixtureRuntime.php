<?php

namespace App\ProtectedOperations\DraftSimulation;

use App\Ai\Drivers\FakeDriver;
use App\Models\User;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Throwable;

/** Fixture-only draft runtime bound to an authorized session and immutable draft. */
final class FixtureRuntime
{
    public function extract(Request $request, KnowledgeSnapshot $draft, string $client, ?array $seed = null): ?array
    {
        $data = $this->run($request, $draft, 'extract', ['client' => $client, 'intents' => $draft->intentIds()], $seed);
        if (! is_array($data) || ! is_string($data['client_intent'] ?? null) || ! in_array($data['client_intent'], [...$draft->intentIds(), 'unclear'], true)) {
            return null;
        }

        $slots = $data['slot_updates'] ?? [];
        if (! is_array($slots) || array_is_list($slots) && $slots !== [] || array_diff(array_keys($slots), LeadState::SLOTS)) {
            return null;
        }
        foreach ($slots as $key => $value) {
            if (in_array($key, ['categories', 'brands_interest'], true)) {
                if ($value !== null && (! is_array($value) || ! array_is_list($value) || count(array_filter($value, 'is_string')) !== count($value))) {
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
                    || ($field === 'refused_slots' && array_diff($value, LeadState::SLOTS))
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

    public function check(Request $request, KnowledgeSnapshot $draft, string $client, string $answer, array $expectations): ?array
    {
        $data = $this->run($request, $draft, 'judge', ['client' => $client, 'answer' => $answer, 'expectations' => array_values($expectations)]);
        if (! is_array($data) || ! is_array($data['checks'] ?? null)) {
            return null;
        }
        $checks = [];
        foreach ($expectations as $expectation) {
            if (! is_string($expectation) || ! is_bool($data['checks'][$expectation] ?? null)) {
                return null;
            }
            $checks[$expectation] = $data['checks'][$expectation];
        }

        return $checks;
    }

    public function body(KnowledgeSnapshot $draft, string $stage, array $input): array
    {
        return ['model' => 'fake-draft-'.$stage, 'draft' => $draft->releaseId(), 'checksum' => $draft->checksum(), 'input' => $input];
    }

    private function run(Request $request, KnowledgeSnapshot $draft, string $stage, array $input, ?array $seed = null): ?array
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($draft, $stage, $input, $seed): ?array {
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
                            default => null,
                        };
                        if ($checked === null) {
                            return null;
                        }
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

                return is_array($fixtures) ? ($fixtures[$input['client']] ?? null) : null;
            }
        });
    }
}
