<?php

namespace App\Privacy\Secrets;

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
        $nodes = 0;
        $bytes = 0;
        $chunks = [];
        try {
            $this->walk($payload, 0, $nodes, $bytes, $chunks);
            $this->inspect(implode('', $chunks));
            for ($i = 0, $count = count($chunks) - 1; $i < $count; $i++) {
                $this->inspect($chunks[$i].$chunks[$i + 1]);
            }
        } catch (Throwable) {
            // Never chain an exception that could contain customer data.
            throw new InvalidArgumentException('AI data cannot be safely inspected for secrets.');
        }
    }

    private function walk(#[\SensitiveParameter] mixed $value, int $depth, int &$nodes, int &$bytes, #[\SensitiveParameter] array &$chunks, bool $toolContext = false, bool $toolArguments = false): void
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
                    && $this->isSensitiveKey($this->normalize($value[$classifier], $bytes))) {
                    throw new InvalidArgumentException;
                }
            }
            foreach ($value as $key => $child) {
                if (is_string($key)) {
                    $normalizedKey = $this->normalize($key, $bytes);
                    if ($this->isSensitiveKey($normalizedKey) && $this->nonempty($child)) {
                        throw new InvalidArgumentException;
                    }
                    $this->inspect($normalizedKey);
                }
                $name = is_string($key) ? preg_replace('/[^a-z0-9]+/', '', mb_strtolower(preg_replace('/\p{Cf}+/u', '', $normalizedKey) ?? '')) : '';
                $childContext = $toolContext || in_array($name, ['toolcalls', 'functioncall', 'toolcall', 'function', 'tooluse'], true);
                $this->walk($child, $depth + 1, $nodes, $bytes, $chunks, $childContext, $toolContext && $name === 'arguments');
            }

            return;
        }
        if (is_string($value)) {
            $text = $this->normalize($value, $bytes);
            $this->inspect($text);
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
                $this->walk($decoded, $depth + 1, $nodes, $bytes, $chunks, $toolContext);
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

    private function isSensitiveKey(string $key): bool
    {
        $key = preg_replace('/\p{Cf}+/u', '', $key) ?? '';
        $key = preg_replace('/([\p{Ll}\p{Nd}])(\p{Lu})/u', '$1_$2', $key) ?? '';
        $key = preg_replace('/(\p{Lu})(\p{Lu}\p{Ll})/u', '$1_$2', $key) ?? '';
        $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '_', $key) ?? '');

        return preg_match('/(?:^|_)(?:password|passwd|pwd|passphrase|api_?key|access_?token|refresh_?token|token|client_?secret|secret|private_?key|authorization|пароль|секрет)(?:_|$)/u', $key) === 1;
    }

    private function nonempty(#[\SensitiveParameter] mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private function inspect(#[\SensitiveParameter] string $text): void
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
            if ($this->isSensitiveKey($field[1]) && (($field[2] ?? '') !== '' || (isset($field[3]) && ! in_array($field[3], ['null', '""'], true)))) {
                throw new InvalidArgumentException;
            }
        }
        $basicMatched = preg_match_all('/\bBasic\s+([A-Za-z0-9+\/]{8,}={0,2})/i', $plain, $basicTokens);
        if ($basicMatched === false) {
            throw new InvalidArgumentException;
        }
        foreach ($basicTokens[1] as $encoded) {
            $credentials = base64_decode($encoded, true);
            if (is_string($credentials) && str_contains($credentials, ':')) {
                throw new InvalidArgumentException;
            }
        }
        $patterns = [
            '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----/i',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{8,}={0,2}\b/i',
            '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
            '/\b[a-z][a-z0-9+.-]*:\/\/[^@\s\/:]+:[^@\s\/]+@/i',
            '/\b(?:sk|pk)_(?:live|test)_[A-Za-z0-9_-]{12,}\b/i',
            '/\b(?:sk-|ghp_|github_pat_|glpat-|xox[baprs]-)[A-Za-z0-9_-]{12,}\b/i',
            '/\b(?:api[\s_-]*key|access[\s_-]*token|refresh[\s_-]*token|client[\s_-]*secret|password|passwd|pwd|passphrase|private[\s_-]*key|authorization|secret|token|пароль|секрет)["\']?\s*[:=]\s*["\']?[^\s"\',;}]{1,}/iu',
        ];
        foreach ($patterns as $pattern) {
            $matched = preg_match($pattern, $plain);
            if ($matched !== 0) {
                throw new InvalidArgumentException;
            }
        }
    }
}
