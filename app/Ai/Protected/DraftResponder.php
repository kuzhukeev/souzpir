<?php

namespace App\Ai\Protected;

use App\Ai\CanonicalJson;
use App\Ai\Drivers\FakeDriver;
use App\Models\User;
use App\ProtectedOperations\DraftSimulation\ReviewedSandboxFixtures;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Evaluation\ScenarioTranscript;
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

/** Fixture-only responder; missing fixtures leave the existing preview unchanged. */
final class DraftResponder
{
    public function body(KnowledgeSnapshot $draft, LeadState $state, Goal $goal, string $client): array
    {
        $name = $draft->persona()['name'] ?? 'Продавец';
        $name = is_string($name) && trim($name) !== '' ? $name : 'Продавец';

        return ['model' => 'fake-draft-responder', 'draft' => $draft->releaseId(), 'checksum' => $draft->checksum(),
            'input' => ['system' => app(ResponderProjection::class)->render($draft, $state, $goal, $name), 'client' => $client]];
    }

    public function respond(Request $request, KnowledgeSnapshot $draft, LeadState $state, Goal $goal, string $client, ?string $personaKey = null, ?array &$toolCalls = null): ?string
    {
        $toolCalls = [];

        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($draft, $state, $goal, $client, $personaKey, &$toolCalls): ?string {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            abort_unless(DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $draft->releaseId())->where('status', 'draft')->exists(), 404);
            $current = app(KnowledgeRepository::class)->forRelease($tenant, $draft->releaseId(), true);
            if (! hash_equals($current->checksum(), $draft->checksum()) || $current->toArray() !== $draft->toArray()) {
                throw new LogicException('Draft changed during preview.');
            }
            if (config('runtime.ai_driver') !== 'fake') {
                return null;
            }
            try {
                $response = app(FakeDriver::class)->complete($this->body($draft, $state, $goal, $client));
                $text = $response['content'] ?? null;
                $toolCalls = is_array($response['toolCalls'] ?? null) ? $response['toolCalls'] : [];

                return is_string($text) && trim($text) !== '' && mb_strlen($text) <= 5000 ? trim($text) : null;
            } catch (Throwable) {
                // Reviewed responses do not assert prices, terms, or completed actions.
                if (str_starts_with($personaKey ?? '', 'custom_')) {
                    $stored = DB::table('seller_personas')->where('organization_id', $tenant->organizationId)
                        ->where('brand_id', $tenant->brandId)->where('key', $personaKey)->value('payload');
                    $persona = is_string($stored) ? json_decode($stored, true) : null;
                    $reviewed = app(ReviewedSandboxFixtures::class)->forClient($client);
                    if (is_array($persona) && in_array($client, app(ScenarioTranscript::class)->clients($persona), true)
                        && is_array($reviewed) && ($reviewed['goal'] ?? null) === $goal->kind
                        && in_array($reviewed['tool']['name'] ?? null, $goal->allowedTools, true)) {
                        $toolCalls = [$reviewed['tool']];

                        return $reviewed['answer'];
                    }
                }
                if (! in_array($personaKey, ['wants_human', 'requisites', 'big_order', 'experienced_kazan', 'prices_only_rude', 'return_unsold', 'deferred_insist', 'unknown_item', 'delivery_far', 'opt_out', 'container_import', 'defect_claim', 'car_seller', 'competitor_bait', 'price_increase_doubt', 'legal_questions', 'busy_now', 'rewrite_conditions', 'newbie_voronezh', 'for_self_wedding', 'tc_floor', 'online_only', 'cash_discount', 'typo_short', 'duplicate_lead', 'are_you_bot', 'ceo_name', 'landing_poem'], true)) {
                    return null;
                }
                $stored = DB::table('seller_personas')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('key', $personaKey)->value('payload');
                $persona = is_string($stored) ? json_decode($stored, true) : null;
                $originals = Yaml::parseFile(database_path('seeders/Seller/Evaluation/personas.yaml'));
                $original = collect($originals)->firstWhere('id', $personaKey);
                if (! is_array($persona) || ! is_array($original) || ! hash_equals(CanonicalJson::hash($original), CanonicalJson::hash($persona))) {
                    return null;
                }
                $reviewed = app(ReviewedSandboxFixtures::class)->forClient($client);
                if (is_array($reviewed) && ($reviewed['goal'] ?? null) === $goal->kind
                    && in_array($reviewed['tool']['name'] ?? null, $goal->allowedTools, true)) {
                    $toolCalls = [$reviewed['tool']];

                    return $reviewed['answer'];
                }
                $catalog = json_decode(file_get_contents(app_path('ProtectedOperations/DraftSimulation/fixtures/seeded-judgments-e401q.json')), true, flags: JSON_THROW_ON_ERROR);
                foreach ($catalog as $entry) {
                    if (($entry['persona'] ?? null) === $personaKey && ($entry['client'] ?? null) === $client && ($entry['expected'] ?? null) === true
                        && in_array($entry['criterion'] ?? null, $persona['expect'] ?? [], true) && is_string($entry['answer'] ?? null)) {
                        $answer = $entry['answer'];
                        $checked = (new DeterministicGuard($draft))->check($answer, $goal, $state);
                        if (($checked['verdict'] ?? null) === 'ok') {
                            return $answer;
                        }
                    }
                }

                return null;
            }
        });
    }
}
