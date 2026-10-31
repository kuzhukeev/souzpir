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

    private function walk(#[\SensitiveParameter] mixed $value, int $depth, int &$nodes, int &$bytes, #[\SensitiveParameter] array &$chunks): void
    {
        if ($depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES) {
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
                $this->walk($child, $depth + 1, $nodes, $bytes, $chunks);
            }

            return;
        }
        if (is_string($value)) {
            $text = $this->normalize($value, $bytes);
            $this->inspect($text);
            $chunks[] = $text;
            $trimmed = trim($text);
            if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $this->walk($decoded, $depth + 1, $nodes, $bytes, $chunks);
                }
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
        $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '_', $key) ?? '');

        return preg_match('/(?:^|_)(?:password|passwd|passphrase|api_?key|access_?token|refresh_?token|client_?secret|secret|private_?key|authorization|пароль|секрет)(?:_|$)/u', $key) === 1;
    }

    private function nonempty(#[\SensitiveParameter] mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private function inspect(#[\SensitiveParameter] string $text): void
    {
        $plain = preg_replace('/\p{Cf}+/u', '', $text);
        if ($plain === null || preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $plain) !== 0) {
            throw new InvalidArgumentException;
        }
        $patterns = [
            '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----/i',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{8,}={0,2}\b/i',
            '/\b(?:sk|pk)_(?:live|test)_[A-Za-z0-9_-]{12,}\b/i',
            '/\b(?:sk-|ghp_|github_pat_|glpat-|xox[baprs]-)[A-Za-z0-9_-]{12,}\b/i',
            '/\b(?:api[\s_-]*key|access[\s_-]*token|refresh[\s_-]*token|client[\s_-]*secret|password|passwd|passphrase|private[\s_-]*key|пароль|секрет)\s*[:=]\s*["\']?[^\s"\',;]{4,}/iu',
        ];
        foreach ($patterns as $pattern) {
            $matched = preg_match($pattern, $plain);
            if ($matched !== 0) {
                throw new InvalidArgumentException;
            }
        }
    }
}
