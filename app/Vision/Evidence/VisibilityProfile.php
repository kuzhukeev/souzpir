<?php

namespace App\Vision\Evidence;

final class VisibilityProfile
{
    /**
     * Explicit aliases stay authoritative. Only an own-site, mixed letter/digit
     * label is inferred (Tort4U from tort4u.ru); ordinary words and transliterations
     * require an operator alias. Never infer identity from a third-party URL.
     *
     * @return list<string>
     */
    public static function recognitionAliases(#[\SensitiveParameter] array $profile): array
    {
        $aliases = array_values(array_unique(array_filter([
            $profile['name'] ?? null, ...($profile['aliases'] ?? []),
            $profile['brand_domain'] ?? null, ...($profile['domains'] ?? []),
        ], fn (mixed $value): bool => is_string($value) && trim($value) !== '')));
        foreach (array_filter([$profile['brand_domain'] ?? null, ...($profile['domains'] ?? [])], 'is_string') as $domain) {
            $host = self::host($domain);
            $labels = explode('.', $host);
            $stem = $labels[0] ?? '';
            if (count($labels) !== 2 || ! preg_match('/^(?=.{4,63}$)(?=.*[a-z])(?=.*[0-9])[a-z0-9]+$/D', $stem)
                || self::isAmbiguous($stem, $profile)) {
                continue;
            }
            $aliases[] = $stem;
        }

        return array_values(array_unique($aliases));
    }

    public static function isAmbiguous(#[\SensitiveParameter] string $value, #[\SensitiveParameter] array $profile = []): bool
    {
        $path = resource_path('prompts/Vision/Evidence/ambiguous-words.ru.txt');
        $words = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $words = array_merge($words, [(string) ($profile['category'] ?? '')], array_values($profile['category_forms'] ?? []));

        return in_array(self::alias($value), array_map(fn ($word): string => self::alias((string) $word), $words), true);
    }

    public static function host(#[\SensitiveParameter] string $website): string
    {
        $host = parse_url(str_contains($website, '://') ? $website : 'https://'.$website, PHP_URL_HOST);
        $host = mb_strtolower(rtrim((string) $host, '.'), 'UTF-8');
        if (function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }

        return preg_replace('/^www\\./', '', $host) ?? '';
    }

    public static function alias(#[\SensitiveParameter] string $value): string
    {
        return mb_strtolower(self::text($value), 'UTF-8');
    }

    public static function text(#[\SensitiveParameter] string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        return trim(preg_replace('/\\s+/u', ' ', $value) ?? $value);
    }
}
