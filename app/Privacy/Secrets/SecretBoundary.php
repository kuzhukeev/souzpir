<?php

namespace App\Privacy\Secrets;

use App\Privacy\InspectionOutcome;
use InvalidArgumentException;
use Normalizer;
use Throwable;

/**
 * A conservative secondary barrier, not proof that arbitrary text contains no secrets.
 * The caller must independently authorize each data class and source before this check.
 */
final class SecretBoundary
{
    private const MAX_DEPTH = 20;

    private const MAX_NODES = 20000;

    private const MAX_BYTES = 4194304;

    private const MAX_ROUNDS = 6;

    public function assertSafe(#[\SensitiveParameter] array $payload): void
    {
        try {
            $this->inspectInternal($payload, strict: false);
        } catch (Throwable) {
            // Never chain an exception that could contain customer data.
            throw new InvalidArgumentException('AI data cannot be safely inspected for secrets.');
        }
    }

    /** An explicit matched rule needs review; invalid input or inspection failure is unavailable. */
    public function inspectTyped(#[\SensitiveParameter] array $payload): InspectionOutcome
    {
        try {
            return $this->inspectInternal($payload, strict: true)
                ? InspectionOutcome::ReviewRequired
                : InspectionOutcome::Clear;
        } catch (Throwable) {
            return InspectionOutcome::Unavailable;
        }
    }

    private function inspectInternal(#[\SensitiveParameter] array $payload, bool $strict): bool
    {
        if ($strict) {
            $this->validateTypedToolCalls($payload);
        }
        $nodes = 0;
        $bytes = 0;
        $chunks = [];
        $review = false;
        $this->walk($payload, 0, $nodes, $bytes, $chunks, $review, strict: $strict);
        $this->inspect(implode('', $chunks), $strict, $review);
        for ($i = 0, $count = count($chunks) - 1; $i < $count; $i++) {
            $this->inspect($chunks[$i].$chunks[$i + 1], $strict, $review);
        }

        return $review;
    }

    /** Inspect only explicit message tool-call envelopes; this is not schema validation. */
    private function validateTypedToolCalls(#[\SensitiveParameter] array $payload): void
    {
        if (! array_key_exists('messages', $payload)) {
            return;
        }
        $messages = $payload['messages'];
        if (! is_array($messages) || ! array_is_list($messages)) {
            throw new InvalidArgumentException;
        }
        $visited = 0;
        foreach ($messages as $message) {
            if (++$visited > self::MAX_NODES || ! is_array($message)) {
                throw new InvalidArgumentException;
            }
            if (! array_key_exists('tool_calls', $message)) {
                continue;
            }
            $calls = $message['tool_calls'];
            if (! is_array($calls) || ! array_is_list($calls)) {
                throw new InvalidArgumentException;
            }
            foreach ($calls as $call) {
                if (++$visited > self::MAX_NODES || ! is_array($call)) {
                    throw new InvalidArgumentException;
                }
                $function = $call['function'] ?? null;
                if (! is_array($function) || ! array_key_exists('arguments', $function)
                    || (! is_array($function['arguments']) && ! is_string($function['arguments']))) {
                    throw new InvalidArgumentException;
                }
            }
        }
    }

    private function walk(#[\SensitiveParameter] mixed $value, int $depth, int &$nodes, int &$bytes, #[\SensitiveParameter] array &$chunks, bool &$review, bool $toolContext = false, bool $toolArguments = false, bool $strict = false): void
    {
        if ($depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES) {
            throw new InvalidArgumentException;
        }
        if ($toolArguments && ! is_array($value) && ! is_string($value)) {
            throw new InvalidArgumentException;
        }
        if (is_array($value)) {
            foreach (['topic', 'type', 'name', 'key', 'label', 'field'] as $classifier) {
                if (isset($value[$classifier]) && is_string($value[$classifier])
                    && $this->isSensitiveKey($this->normalize($value[$classifier], $bytes), $strict)) {
                    $this->noteReview($strict, $review);
                }
            }
            foreach ($value as $key => $child) {
                if (is_string($key)) {
                    $normalizedKey = $this->normalize($key, $bytes);
                    if ($this->isSensitiveKey($normalizedKey, $strict) && $this->nonempty($child)) {
                        $this->noteReview($strict, $review);
                    }
                    $this->inspect($normalizedKey, $strict, $review);
                }
                $name = '';
                if (is_string($key)) {
                    $cleaned = preg_replace('/\p{Cf}+/u', '', $normalizedKey);
                    if ($strict && $cleaned === null) {
                        throw new InvalidArgumentException;
                    }
                    $name = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($cleaned ?? ''));
                    if ($strict && $name === null) {
                        throw new InvalidArgumentException;
                    }
                }
                $childContext = $toolContext || in_array($name, ['toolcalls', 'functioncall', 'toolcall', 'function', 'tooluse'], true);
                $this->walk($child, $depth + 1, $nodes, $bytes, $chunks, $review, $childContext, $toolContext && $name === 'arguments', $strict);
            }

            return;
        }
        if (is_string($value)) {
            $text = $this->normalize($value, $bytes);
            $this->inspect($text, $strict, $review);
            $chunks[] = $text;
            $parsed = false;
            if ($toolArguments) {
                $original = trim($value);
                if (! str_starts_with($original, '{') && ! str_starts_with($original, '[')) {
                    throw new InvalidArgumentException;
                }
                json_decode($original, true, 22);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException;
                }
            }
            foreach (array_unique([$value, $text]) as $candidate) {
                $trimmed = trim($candidate);
                if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
                    continue;
                }
                // Invalid strings are tolerated only outside a tool-argument context.
                json_decode($trimmed, true, 22);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
                $decoded = (new JsonObjectInspector)->decode($trimmed);
                $parsed = true;
                $this->walk($decoded, $depth + 1, $nodes, $bytes, $chunks, $review, $toolContext, strict: $strict);
            }
            if ($toolArguments && ! $parsed) {
                throw new InvalidArgumentException;
            }

            return;
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException;
        }
        if ($value !== null && ! is_bool($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException;
        }
    }

    private function normalize(#[\SensitiveParameter] string $value, int &$bytes): string
    {
        $bytes += strlen($value);
        if ($bytes > self::MAX_BYTES || ! mb_check_encoding($value, 'UTF-8') || ! class_exists(Normalizer::class)) {
            throw new InvalidArgumentException;
        }
        for ($i = 0; $i < self::MAX_ROUNDS; $i++) {
            $text = Normalizer::normalize($value, Normalizer::FORM_KC);
            if (! is_string($text)) {
                throw new InvalidArgumentException;
            }
            $text = html_entity_decode(rawurldecode($text), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
            $text = preg_replace_callback('/\\\\u(?:[dD][89aAbB][0-9a-fA-F]{2}\\\\u[dD][c-fC-F][0-9a-fA-F]{2}|[0-9a-fA-F]{4})/', static function (array $match): string {
                $decoded = json_decode('"'.$match[0].'"');
                if (! is_string($decoded)) {
                    throw new InvalidArgumentException;
                }

                return $decoded;
            }, $text);
            if ($text === null) {
                throw new InvalidArgumentException;
            }
            if (! mb_check_encoding($text, 'UTF-8') || strlen($text) > self::MAX_BYTES) {
                throw new InvalidArgumentException;
            }
            if ($text === $value) {
                return $text;
            }
            $value = $text;
        }

        throw new InvalidArgumentException;
    }

    private function isSensitiveKey(string $key, bool $strict): bool
    {
        $key = preg_replace('/\p{Cf}+/u', '', $key);
        if ($strict && $key === null) {
            throw new InvalidArgumentException;
        }
        $key ??= '';
        $key = preg_replace('/([\p{Ll}\p{Nd}])(\p{Lu})/u', '$1_$2', $key) ?? '';
        if ($strict && preg_last_error() !== PREG_NO_ERROR) {
            throw new InvalidArgumentException;
        }
        $key = preg_replace('/(\p{Lu})(\p{Lu}\p{Ll})/u', '$1_$2', $key) ?? '';
        if ($strict && preg_last_error() !== PREG_NO_ERROR) {
            throw new InvalidArgumentException;
        }
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '_', $key);
        if ($strict && $key === null) {
            throw new InvalidArgumentException;
        }
        $key = mb_strtolower($key ?? '');

        $matched = preg_match('/(?:^|_)(?:password|passwd|pwd|passphrase|api_?key|access_?token|refresh_?token|token|client_?secret|secret|private_?key|authorization|пароль|секрет)(?:_|$)/u', $key);
        if ($strict && $matched === false) {
            throw new InvalidArgumentException;
        }

        return $matched === 1;
    }

    private function nonempty(#[\SensitiveParameter] mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private function inspect(#[\SensitiveParameter] string $text, bool $strict, bool &$review): void
    {
        $plain = preg_replace('/\p{Cf}+/u', '', $text);
        if ($plain !== null) {
            // Inspection only: expose JSON-escaped punctuation without changing the payload.
            $plain = str_replace('\\', '', $plain);
        }
        if ($plain === null || preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $plain) !== 0) {
            throw new InvalidArgumentException;
        }
        $fieldsMatched = preg_match_all('/["\']([^"\'\r\n]{1,256})["\']\s*:\s*(?:["\']([^"\']*)["\']|([^\s,}\]]+))/u', $plain, $fields, PREG_SET_ORDER);
        if ($fieldsMatched === false) {
            throw new InvalidArgumentException;
        }
        foreach ($fields as $field) {
            if ($this->isSensitiveKey($field[1], $strict) && (($field[2] ?? '') !== '' || (isset($field[3]) && ! in_array($field[3], ['null', '""'], true)))) {
                $this->noteReview($strict, $review);
            }
        }
        // Even ':' encodes to just two Base64 characters (plus padding).
        $basicMatched = preg_match_all('/\bBasic\s+([A-Za-z0-9+\/]{2,}={0,2})/i', $plain, $basicTokens);
        if ($basicMatched === false) {
            throw new InvalidArgumentException;
        }
        foreach ($basicTokens[1] as $encoded) {
            $credentials = base64_decode($encoded, true);
            if (is_string($credentials) && str_contains($credentials, ':')) {
                $this->noteReview($strict, $review);
            }
        }
        $patterns = [
            '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----/i',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+={0,2}/i',
            '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
            '/\b[a-z][a-z0-9+.-]*:\/\/[^@\s\/:]+:[^@\s\/]+@/i',
            '/\b(?:sk|pk)_(?:live|test)_[A-Za-z0-9_-]{12,}\b/i',
            '/\b(?:sk-|ghp_|github_pat_|glpat-|xox[baprs]-)[A-Za-z0-9_-]{12,}\b/i',
            '/\b(?:api[\s_-]*key|access[\s_-]*token|refresh[\s_-]*token|client[\s_-]*secret|password|passwd|pwd|passphrase|private[\s_-]*key|authorization|secret|token|пароль|секрет)["\']?\s*[:=]\s*["\']?[^\s"\',;}]{1,}/iu',
        ];
        foreach ($patterns as $pattern) {
            $matched = preg_match($pattern, $plain);
            if ($matched === false) {
                throw new InvalidArgumentException;
            }
            if ($matched === 1) {
                $this->noteReview($strict, $review);
            }
        }
    }

    private function noteReview(bool $strict, bool &$review): void
    {
        if (! $strict) {
            throw new InvalidArgumentException;
        }
        $review = true;
    }
}
