<?php

namespace App\ProtectedOperations;

use App\Ai\Gateway;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\DeterministicGuard;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\Prohibitions\GuardHitRecorder;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;
use Throwable;

/** Internal deterministic check; the returned verdict is not permission to act or send. */
final class FirstTurnDeterministicGuard
{
    /** Caller must hold the transaction for all protected use of the result. */
    public function evaluateLocked(#[SensitiveParameter] Request $http, #[SensitiveParameter] TurnClaim $claim, #[SensitiveParameter] string $responderId): array
    {
        try {
            if (DB::connection()->transactionLevel() < 1) {
                throw new LogicException;
            }
            // This revalidates actor, session, brand, source, result, accounting
            // and freshness and acquires the source and responder locks.
            $candidate = app(Gateway::class)->readProtectedResponderLocked($http, $claim, $responderId);
            if (($candidate['status'] ?? null) !== 'candidate' || ! is_string($candidate['text'] ?? null)) {
                throw new LogicException;
            }
            $stage = DB::table('c02_responder_stages')->where('id', $responderId)->where('turn_id', $claim->turnId)->lockForUpdate()->first();
            if (! $stage) {
                throw new LogicException;
            }
            $computed = app(FirstTurnGoal::class)->evaluateLocked($http, $claim, $stage->extractor_stage_id, $stage->first_state_id);
            $knowledge = app(KnowledgeRepository::class)->published(app(TenantContext::class));
            if ($stage->release_id !== $knowledge->releaseId()) {
                throw new LogicException;
            }

            $continuation = DB::table('e309_responder_continuations')->where('stage_id', $responderId)->first();
            $payload = $continuation ? json_decode(Crypt::decryptString($continuation->payload_encrypted), true, 512, JSON_THROW_ON_ERROR) : [];
            $toolResults = $payload['tool_results'] ?? [];
            $called = array_column($toolResults, 'name');
            $tenant = app(TenantContext::class);
            $catalogPricesEnabled = (bool) DB::table('brand_seller_settings')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->value('catalog_prices_enabled');
            $catalogKnownPrices = [];
            foreach ($toolResults as $toolResult) {
                if (($toolResult['name'] ?? null) !== 'check_stock') {
                    continue;
                }
                foreach ($toolResult['result']['items'] ?? [] as $item) {
                    foreach (['price', 'wholesale_price'] as $field) {
                        if (isset($item[$field]) && is_numeric($item[$field])) {
                            $catalogKnownPrices[] = (float) $item[$field];
                        }
                    }
                }
            }

            $result = (new DeterministicGuard($knowledge))->check($candidate['text'], $computed['goal'], new LeadState($computed['state']), [
                'tools_called' => $called, 'catalog_prices_enabled' => $catalogPricesEnabled, 'catalog_known_prices' => $catalogKnownPrices,
            ]);
            $priorAgentMessages = DB::table('messages')->where('organization_id', $claim->organizationId)->where('brand_id', $claim->brandId)
                ->where('conversation_id', $claim->conversationId)->where('role', 'agent')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(['text', 'meta'])
                ->map(fn ($row) => ['text' => (string) $row->text, 'meta' => json_decode($row->meta ?: '{}', true) ?: []])->all();
            $questionLimit = (int) ($knowledge->policy()['limits']['max_consecutive_questions'] ?? 2);
            if ($this->exceedsConsecutiveQuestionLimit($priorAgentMessages, $candidate['text'], $questionLimit)) {
                $result['issues'][] = 'слишком много вопросов подряд — дай пользу без вопроса, новый вопрос задай позже';
                $result['verdict'] = 'regenerate';
            }
            app(GuardHitRecorder::class)->record(app(TenantContext::class), $responderId, $knowledge, $candidate['text'], $result);

            return $result;
        } catch (Throwable) {
            throw new LogicException('Protected first-turn guard unavailable.');
        }
    }

    /**
     * Pure rule: at most $limit consecutive bot replies ending in a question
     * (default 2, настраивается в policy.limits.max_consecutive_questions);
     * the next one must give value without asking. Напоминания
     * (followup_nudge) не считаются и не прерывают цепочку.
     *
     * @param  list<array{text:string,meta:array}>  $priorAgentMessagesNewestFirst
     */
    public function exceedsConsecutiveQuestionLimit(array $priorAgentMessagesNewestFirst, string $candidateText, int $limit = 2): bool
    {
        if (! str_ends_with(rtrim($candidateText), '?')) {
            return false;
        }
        $streak = 0;
        foreach ($priorAgentMessagesNewestFirst as $message) {
            $meta = $message['meta'] ?? [];
            if (($meta['goal_kind'] ?? null) === 'followup_nudge' || ($meta['goal']['kind'] ?? null) === 'followup_nudge') {
                continue;
            }
            if (! str_ends_with(rtrim((string) ($message['text'] ?? '')), '?')) {
                break;
            }
            if (++$streak >= $limit) {
                return true;
            }
        }

        return false;
    }
}
