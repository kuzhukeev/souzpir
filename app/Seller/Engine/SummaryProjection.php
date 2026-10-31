<?php

namespace App\Seller\Engine;

use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Pure prototype-compatible transformations only; this class neither authorizes
 * its inputs nor calls a gateway or persists a result. An executable stage must
 * obtain history and the previous summary from the trusted runtime boundary.
 */
final class SummaryProjection
{
    // Technical safety ceilings, not commercial limits; oversized input is rejected, never truncated.
    private const MAX_HISTORY_ITEMS = 128;

    private const MAX_INPUT_BYTES = 262144;

    private const MAX_OUTPUT_BYTES = 262144;

    /**
     * Builds data only; rendered prompt and history remain untrusted until verified by the runtime.
     * The history and previous summary are conversation_content. A future caller must also
     * classify every other actually included data class and apply the Gateway privacy boundary.
     * This method does not authorize a call, verify a tenant, or persist an outcome.
     */
    public function request(#[SensitiveParameter] string $renderedPrompt, #[SensitiveParameter] array $history, #[SensitiveParameter] string $model, #[SensitiveParameter] string $promptVersion, #[SensitiveParameter] string $promptChecksum): AiRequest
    {
        if ($promptVersion === '' || strlen($promptVersion) > 128 || strlen($model) > 256 || ! mb_check_encoding($model, 'UTF-8') || ! mb_check_encoding($promptVersion, 'UTF-8') || ! preg_match('/\A[0-9a-f]{64}\z/', $promptChecksum)) {
            throw new InvalidArgumentException('Invalid summary prompt identity.');
        }
        if (! mb_check_encoding($renderedPrompt, 'UTF-8')) {
            throw new InvalidArgumentException('Invalid summary input.');
        }
        if (strlen($renderedPrompt) > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('Summary input exceeds safe bounds.');
        }
        $text = $this->history($history);
        if (strlen($renderedPrompt) + strlen($text) > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('Summary input exceeds safe bounds.');
        }

        return new AiRequest(
            messages: [['role' => 'system', 'content' => $renderedPrompt], ['role' => 'user', 'content' => $text]],
            model: $model,
            temperature: 0.2,
            maxTokens: 300,
            promptVersion: $promptVersion,
            promptChecksum: $promptChecksum,
        );
    }

    /** @param list<array{role:string,text:string}> $history */
    public function history(#[SensitiveParameter] array $history): string
    {
        if (! array_is_list($history) || count($history) > self::MAX_HISTORY_ITEMS) {
            throw new InvalidArgumentException('Invalid summary history.');
        }
        $bytes = 0;
        foreach ($history as $index => $entry) {
            if (! is_array($entry) || ! is_string($entry['role'] ?? null) || ! is_string($entry['text'] ?? null)
                || ! mb_check_encoding($entry['role'], 'UTF-8') || ! mb_check_encoding($entry['text'], 'UTF-8')) {
                throw new InvalidArgumentException('Invalid summary history.');
            }
            // Account for separator and both possible role prefixes before allocating any joined output.
            $prefix = $entry['role'] === 'client' ? 'Клиент: ' : 'Менеджер: ';
            $bytes += strlen($prefix) + strlen($entry['text']) + ($index > 0 ? 1 : 0);
            if ($bytes > self::MAX_INPUT_BYTES) {
                throw new InvalidArgumentException('Summary input exceeds safe bounds.');
            }
        }
        $lines = [];
        foreach ($history as $entry) {
            $lines[] = ($entry['role'] === 'client' ? 'Клиент: ' : 'Менеджер: ').$entry['text'];
        }

        return implode("\n", $lines);
    }

    public function result(#[SensitiveParameter] AiResult $result, #[SensitiveParameter] ?string $previous): ?string
    {
        if (($result->error['code'] ?? null) === 'in_progress') {
            throw new SummaryPending($result->aiCallId);
        }
        // Gateway keeps responses with unknown cost unsettled, including empty responses.
        if ($result->costMicros === null) {
            throw new SummaryUncertain($result->aiCallId);
        }
        if ($result->costMicros < 0) {
            throw new SummaryUnavailable;
        }
        if ($result->error !== null) {
            if (($result->error['code'] ?? null) !== 'empty_response') {
                throw new SummaryUncertain($result->aiCallId);
            }
            if ($result->toolCalls !== [] || ($result->content !== null && (strlen($result->content) > self::MAX_OUTPUT_BYTES || trim($result->content) !== '' || ! mb_check_encoding($result->content, 'UTF-8'))) || ($previous !== null && (strlen($previous) > self::MAX_OUTPUT_BYTES || ! mb_check_encoding($previous, 'UTF-8')))) {
                throw new SummaryUnavailable;
            }

            return $previous;
        }

        if ($result->content === null || $result->toolCalls !== [] || strlen($result->content) > self::MAX_OUTPUT_BYTES || trim($result->content) === '' || ! mb_check_encoding($result->content, 'UTF-8')) {
            throw new SummaryUnavailable;
        }

        return trim($result->content);
    }
}
