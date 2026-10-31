<?php

namespace App\Seller\Engine;

use App\Contracts\Ai\AiResult;
use Throwable;

/**
 * Pure interpretation of a received result; it neither calls AI nor authorizes an answer.
 * The runtime must independently establish the trusted turn, data classes and effect rights.
 */
final class GuardModelResult
{
    /** @return array{status: string, verdict: ?bool, issues: list<string>} Issues are sensitive internal model output. */
    public function interpret(#[\SensitiveParameter] AiResult $result): array
    {
        try {
            // Gateway does not settle a response when its cost is unknown.
            if ($result->costMicros === null) {
                return ['status' => 'blocked', 'verdict' => null, 'issues' => []];
            }
            if ($result->costMicros < 0) {
                return ['status' => 'unavailable', 'verdict' => null, 'issues' => []];
            }
            if ($result->error !== null) {
                // Only the gateway's confirmed empty response is a known terminal result.
                // Pending and unknown/unsettled outcomes cannot become a completed check.
                return ['status' => ($result->error['code'] ?? null) === 'empty_response' ? 'unavailable' : 'blocked', 'verdict' => null, 'issues' => []];
            }
            if ($result->toolCalls !== [] || ! is_string($result->content) || strlen($result->content) > 65536) {
                return ['status' => 'unavailable', 'verdict' => null, 'issues' => []];
            }
            // Match the prototype's LlmResult::json semantics, including fenced JSON.
            $decoded = $result->json();
            if (! is_array($decoded) || $decoded === []) {
                return ['status' => 'unavailable', 'verdict' => null, 'issues' => []];
            }
            $rawIssues = $decoded['issues'] ?? [];
            if (! is_array($rawIssues) || ! array_is_list($rawIssues) || count($rawIssues) > 100) {
                return ['status' => 'unavailable', 'verdict' => null, 'issues' => []];
            }
            $valid = array_keys($decoded) === ['ok', 'issues'] || array_keys($decoded) === ['issues', 'ok'];
            $valid = $valid && $this->uniqueRootKeys($result->content) && is_bool($decoded['ok'] ?? null)
                && array_key_exists('issues', $decoded) && count(array_filter($rawIssues, 'is_string')) === count($rawIssues);
            if (! $valid) {
                return ['status' => 'unavailable', 'verdict' => null, 'issues' => []];
            }

            // The donor ignores issues when ok is true and retains up to three on false.
            return ['status' => 'evaluated', 'verdict' => $decoded['ok'], 'issues' => $decoded['ok'] ? [] : array_slice($rawIssues, 0, 3)];
        } catch (Throwable) {
            // Model content and parser errors may contain sensitive values.
            return ['status' => 'unavailable', 'verdict' => null, 'issues' => []];
        }
    }

    /** AiResult::json accepts fenced/embedded JSON, but json_decode alone loses duplicate keys. */
    private function uniqueRootKeys(#[\SensitiveParameter] string $content): bool
    {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($content));
        if (! is_string($text)) {
            return false;
        }
        try {
            $value = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            if (! preg_match('/\{.*\}/su', $text, $match)) {
                return false;
            }
            $text = $match[0];
            $value = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        }
        // Associative decoding in AiResult::json collapses {} and [] to the
        // same PHP array, so check the original JSON type before trusting it.
        if (! $value instanceof \stdClass || ! property_exists($value, 'issues') || ! is_array($value->issues)) {
            return false;
        }
        $depth = 0;
        $keys = [];
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
            } elseif ($char === '"') {
                $start = $i++;
                while ($i < $length && $text[$i] !== '"') {
                    $i += $text[$i] === '\\' ? 2 : 1;
                }
                if ($i >= $length) {
                    return false;
                }
                $next = $i + 1;
                while ($next < $length && str_contains(" \t\r\n", $text[$next])) {
                    $next++;
                }
                if ($depth === 1 && $next < $length && $text[$next] === ':') {
                    $key = json_decode(substr($text, $start, $i - $start + 1), true, 8, JSON_THROW_ON_ERROR);
                    if (! is_string($key) || isset($keys[$key])) {
                        return false;
                    }
                    $keys[$key] = true;
                }
            }
        }

        return true;
    }
}
