<?php

namespace App\Vision\Evidence;

/** Conservative literal identity: similar extended names must never become a brand match. */
final class ExactMentionVerifier
{
    public function __construct(private EntityNormalizer $normalizer) {}

    public function isProfileName(#[\SensitiveParameter] string $entity, #[\SensitiveParameter] array $profile): bool
    {
        if (! $this->balancedQuotes($entity)) {
            return false;
        }
        $normalized = $this->normalizer->normalize($entity);
        if ($normalized === '') {
            return false;
        }
        foreach (VisibilityProfile::recognitionAliases($profile) as $alias) {
            if ($this->normalizer->normalize($alias) === $normalized) {
                return true;
            }
        }

        return false;
    }

    private function balancedQuotes(#[\SensitiveParameter] string $value): bool
    {
        $stack = [];
        $openings = ['«' => '»', '“' => '”', '„' => '“', '‘' => '’', '"' => '"'];
        $closings = array_values($openings);
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($stack !== [] && end($stack) === $character) {
                array_pop($stack);
            } elseif (isset($openings[$character])) {
                $stack[] = $openings[$character];
            } elseif (in_array($character, $closings, true)) {
                return false;
            }
        }

        return $stack === [];
    }

    public function inText(#[\SensitiveParameter] string $text, #[\SensitiveParameter] string $entity, #[\SensitiveParameter] array $profile): bool
    {
        if (! $this->isProfileName($entity, $profile) || $entity === '') {
            return false;
        }
        $pattern = '/(?<![\p{L}\p{N}_@.-])'.preg_quote($entity, '/').'(?![\p{L}\p{N}_-]|\.[\p{L}\p{N}])/iu';
        if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        foreach ($matches[0] as [$matched, $offset]) {
            $before = substr($text, 0, $offset);
            $after = substr($text, $offset + strlen($matched));
            $openings = ['«' => '»', '“' => '”', '"' => '"'];
            $hasOpening = false;
            $hasClosing = false;
            foreach ($openings as $opening => $closing) {
                $leftQuote = str_ends_with($before, $opening);
                $rightQuote = str_starts_with($after, $closing);
                $hasOpening = $hasOpening || $leftQuote;
                $hasClosing = $hasClosing || $rightQuote;
                if ($leftQuote && $rightQuote) {
                    $before = substr($before, 0, -strlen($opening));
                    $after = substr($after, strlen($closing));
                    $hasOpening = $hasClosing = false;
                    break;
                }
            }
            if ($hasOpening || $hasClosing) {
                continue;
            }
            $left = preg_match('/(?:\A|[\n.!?]\s*|(?<!\p{L})(?:рекомендую|рекомендуем|советую|советуем|выбираю|i recommend)\s+|[0-9]+[.)]\s*)\z/iu', $before);
            $right = preg_match('/\A\s*(?:\z|[!?](?=\s|\z)|\.(?![\p{L}\p{N}]))/u', $after);
            if ($left === 1 && $right === 1) {
                return true;
            }
        }

        return false;
    }
}
