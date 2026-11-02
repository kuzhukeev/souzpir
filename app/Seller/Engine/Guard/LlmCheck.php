<?php

namespace App\Seller\Engine\Guard;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Engine\Goal;
use App\Seller\Engine\GuardDecision;
use App\Seller\Engine\GuardModelResult;
use Illuminate\Http\Request;
use Throwable;

/** Evaluates a protected fake guard candidate; no result authorizes delivery. */
final class LlmCheck
{
    public function __construct(private readonly VerifiedSource $source, private readonly FakeModel $model) {}

    public function check(#[\SensitiveParameter] Request $http, #[\SensitiveParameter] TurnClaim $claim, string $stageId): array
    {
        try {
            if (config('runtime.ai_driver', 'fake') !== 'fake') {
                return $this->uncertain();
            }
            [$request, $context, $deterministic, $goal] = $this->source->load($http, $claim, $stageId);
            if (! $request instanceof AiRequest || ! $context instanceof AiContext || ! $goal instanceof Goal
                || $context->organizationId !== $claim->organizationId || $context->brandId !== $claim->brandId
                || $context->objectType !== 'conversation' || $context->objectId !== $claim->conversationId
                || $context->family !== 'seller' || $context->purpose !== 'guard'
                || $context->dataClasses !== ['conversation_content', 'client_profile', 'knowledge_internal']) {
                return $this->uncertain();
            }
            $decision = new GuardDecision;
            if (! $decision->requiresModel($deterministic, $goal, true)) {
                return $decision->finish($deterministic, $goal, true);
            }
            $result = $this->model->complete($request, $context);
            if (($result->error['code'] ?? null) === 'in_progress') {
                return ['status' => 'in_progress', 'verdict' => null, 'issues' => []];
            }
            if ($result->error !== null || ! $this->validPayload($result->content)) {
                return $this->uncertain();
            }
            $outcome = $decision->finish($deterministic, $goal, true, (new GuardModelResult)->interpret($result));

            return $outcome['status'] === 'completed' ? $outcome : $this->uncertain();
        } catch (Throwable) {
            return $this->uncertain();
        }
    }

    private function validPayload(?string $content): bool
    {
        if ($content === null) {
            return false;
        }
        try {
            $payload = json_decode($content, false, 32, JSON_THROW_ON_ERROR);
            if (! $payload instanceof \stdClass || count(get_object_vars($payload)) !== 2
                || ! is_bool($payload->ok ?? null) || ! is_array($payload->issues ?? null)
                || count(array_filter($payload->issues, 'is_string')) !== count($payload->issues)) {
                return false;
            }

            return $payload->ok ? $payload->issues === [] : $payload->issues !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function uncertain(): array
    {
        return ['status' => 'uncertain', 'verdict' => null, 'issues' => []];
    }
}
