<?php

namespace App\Seller\Engine\Responder;

use App\Contracts\Ai\AiContext;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\ResponderRequest;
use App\Seller\Engine\ResponderResult;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use LogicException;

/** Composes a candidate only; authorization, storage, tools and delivery belong to the runtime. */
final class Responder
{
    public function __construct(private readonly PublicationSource $source, private readonly Projection $projection, private readonly FakeTransport $transport) {}

    public function respond(TenantContext $tenant, string $conversationId, string $key, LeadState $state, Goal $goal,
        array $history, string $model, float $temperature): array
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake' || ! $tenant->organizationId || ! $tenant->brandId || $goal->kind === 'silent') {
            throw new LogicException('Responder unavailable.');
        }
        $input = $this->source->load($tenant);
        $snapshot = $input['snapshot'] ?? null;
        $prompt = $input['prompt'] ?? null;
        if (($input['organization_id'] ?? null) !== $tenant->organizationId || ($input['brand_id'] ?? null) !== $tenant->brandId
            || ($input['release_status'] ?? null) !== 'published' || ! $snapshot instanceof KnowledgeSnapshot || ! is_object($prompt)
            || ($prompt->status ?? null) !== 'published' || ($prompt->name ?? null) !== 'responder'
            || ! (($prompt->organization_id === null && $prompt->brand_id === null)
                || ($prompt->organization_id === $tenant->organizationId && $prompt->brand_id === $tenant->brandId))
            || ! is_string($prompt->body ?? null) || ! is_string($prompt->checksum ?? null) || ! is_string($prompt->version ?? null)
            || ! hash_equals(hash('sha256', $prompt->body), $prompt->checksum)) {
            throw new LogicException('Published responder input unavailable.');
        }
        $name = $snapshot->persona()['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            throw new LogicException('Published responder input unavailable.');
        }
        $system = $this->projection->render($snapshot, $state, $goal, $name);
        $request = (new ResponderRequest)->build($system, $history, $goal->allowedTools, $model, $temperature, $prompt->version, $prompt->checksum);
        $context = new AiContext($tenant->organizationId, $tenant->brandId, 'responder', 'seller', 'conversation', $conversationId, $key,
            ['conversation_content', 'client_profile', 'knowledge_internal']);
        $result = (new ResponderResult)->interpret($this->transport->complete($request, $context));

        return match ($result['status']) {
            'candidate' => ['status' => 'completed', 'text' => $result['text']],
            'pending' => ['status' => 'in_progress', 'text' => null],
            default => ['status' => $result['status'], 'text' => null],
        };
    }
}
