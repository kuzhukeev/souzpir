<?php

namespace App\Seller\Engine\Summarizer;

use App\Ai\ContactMasker;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiRequest;
use App\Seller\Engine\SummaryPending;
use App\Seller\Engine\SummaryProjection;
use App\Seller\Engine\SummaryUnavailable;
use App\Seller\Engine\SummaryUncertain;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use LogicException;

/** Produces a candidate only; persistence and turn finalization belong to the runtime. */
final class Summarizer
{
    public function __construct(private readonly PublicationSource $source, private readonly PromptRenderer $renderer,
        private readonly FakeTransport $transport, private readonly SummaryProjection $projection, private readonly ContactMasker $masker) {}

    public function summarize(TenantContext $tenant, string $conversationId, string $key, string $model): array
    {
        if (config('runtime.ai_driver', 'fake') !== 'fake' || ! $tenant->organizationId || ! $tenant->brandId
            || $conversationId === '' || $key === '' || $model === '') {
            throw new LogicException('Summarizer unavailable.');
        }
        $input = $this->source->load($tenant, $conversationId);
        $prompt = $input['prompt'] ?? null;
        $history = $input['history'] ?? null;
        $previous = $input['previous'] ?? null;
        if (($input['organization_id'] ?? null) !== $tenant->organizationId || ($input['brand_id'] ?? null) !== $tenant->brandId
            || ($input['conversation_id'] ?? null) !== $conversationId || ($input['release_status'] ?? null) !== 'published'
            || ! ($input['snapshot'] ?? null) instanceof KnowledgeSnapshot || ! is_object($prompt)
            || ($prompt->name ?? null) !== 'summary' || ($prompt->status ?? null) !== 'published'
            || ! (($prompt->organization_id === null && $prompt->brand_id === null)
                || ($prompt->organization_id === $tenant->organizationId && $prompt->brand_id === $tenant->brandId))
            || ! is_string($prompt->body ?? null) || ! is_string($prompt->checksum ?? null)
            || ! is_string($prompt->version ?? null) || ! hash_equals(hash('sha256', $prompt->body), $prompt->checksum)
            || ! is_array($history) || ! array_is_list($history) || ! is_null($previous) && ! is_string($previous)) {
            throw new LogicException('Published summary input unavailable.');
        }
        foreach ($history as $message) {
            if (! is_array($message) || ! in_array($message['role'] ?? null, ['client', 'agent'], true)
                || ! is_string($message['text'] ?? null) || array_diff(array_keys($message), ['role', 'text'])) {
                throw new LogicException('Verified summary history unavailable.');
            }
        }
        $rendered = $this->renderer->render($prompt->body, $previous);
        $raw = $this->projection->request($rendered, $history, $model, $prompt->version, $prompt->checksum);
        $messages = $this->masker->messages($raw->messages);
        $request = new AiRequest($messages, $raw->model, $raw->temperature, maxTokens: $raw->maxTokens,
            promptVersion: $raw->promptVersion, promptChecksum: $raw->promptChecksum);
        $context = new AiContext($tenant->organizationId, $tenant->brandId, 'summary', 'seller', 'conversation', $conversationId,
            $key, ['conversation_content']);
        $result = $this->transport->complete($request, $context);
        try {
            if (($result->error['code'] ?? null) === 'empty_response') {
                throw new SummaryUnavailable;
            }
            $summary = $this->projection->result($result, $previous);
            if ($summary === null) {
                throw new SummaryUnavailable;
            }

            return ['status' => 'completed', 'summary' => $summary];
        } catch (SummaryPending) {
            return ['status' => 'in_progress', 'summary' => null];
        } catch (SummaryUncertain) {
            return ['status' => 'uncertain', 'summary' => null];
        } catch (SummaryUnavailable) {
            return ['status' => 'unavailable', 'summary' => null];
        }
    }
}
