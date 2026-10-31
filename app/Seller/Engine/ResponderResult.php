<?php

namespace App\Seller\Engine;

use App\Contracts\Ai\AiResult;
use SensitiveParameter;

/**
 * Local interpretation only: neither a candidate nor a tool signal authorizes
 * an answer, an effect, or another AI call. A runtime must verify result origin,
 * tenant and turn, and apply Guard and its own policies before any action.
 */
final class ResponderResult
{
    private const MAX_TEXT_BYTES = 262144;

    /** @return array{status: string, text: ?string} Candidate text is sensitive and must not be logged. */
    public function interpret(#[SensitiveParameter] AiResult $result): array
    {
        if (($result->error['code'] ?? null) === 'in_progress') {
            return $this->state('pending');
        }
        // Gateway leaves unknown costs unsettled, even for an apparently empty answer.
        if ($result->costMicros === null) {
            return $this->state('uncertain');
        }
        if ($result->costMicros < 0) {
            return $this->state('unavailable');
        }
        if ($result->error !== null) {
            return $this->state(($result->error['code'] ?? null) === 'empty_response' ? 'unavailable' : 'uncertain');
        }
        if (! array_is_list($result->toolCalls)) {
            return $this->state('unavailable');
        }
        // The prototype resolves tools before considering any accompanying text.
        // This fixed signal contains no calls or arguments and does not approve them.
        if ($result->toolCalls !== []) {
            return $this->state('tool_calls');
        }
        $text = $result->content;
        if ($text === null || strlen($text) > self::MAX_TEXT_BYTES || ! mb_check_encoding($text, 'UTF-8')) {
            return $this->state('unavailable');
        }

        // Preserve Orchestrator::clean from the read-only Laravel prototype.
        $text = trim($text);
        $text = preg_replace('/^["«]|["»]$/u', '', $text);
        if (! is_string($text)) {
            return $this->state('unavailable');
        }
        $text = preg_replace('/^(Антон|Менеджер|Ответ)\s*:\s*/u', '', $text);
        if (! is_string($text)) {
            return $this->state('unavailable');
        }
        $text = trim($text);
        if ($text === '') {
            return $this->state('unavailable');
        }

        return ['status' => 'candidate', 'text' => $text];
    }

    private function state(string $status): array
    {
        return ['status' => $status, 'text' => null];
    }
}
