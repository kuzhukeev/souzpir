<?php

namespace App\Vision\Evidence;

/** Extract only literal HTTP(S) links; this class never fetches the source. */
final class SourceExtractor
{
    public function extract(#[\SensitiveParameter] string $text, #[\SensitiveParameter] array $profile): array
    {
        preg_match_all('~https?://[^\s<>"\x{00AB}\x{00BB}]+~ui', $text, $matches);
        $sources = [];
        foreach ($matches[0] as $literal) {
            $literal = rtrim($literal, '.,;:!?)]}');
            $url = $this->normalizeUrl($literal);
            if ($url === null) {
                continue;
            }
            $hash = hash('sha256', $url);
            if (isset($sources[$hash])) {
                continue;
            }
            $domain = parse_url($url, PHP_URL_HOST);
            $own = collect(array_filter([$profile['brand_domain'] ?? null, ...($profile['domains'] ?? [])], 'is_string'))
                ->contains(fn (string $expected): bool => $this->domainMatches($domain, $expected));

            $sources[$hash] = ['url' => $url, 'url_hash' => $hash, 'domain' => $domain,
                'origin' => 'text', 'source_type' => $own ? 'brand_site' : $this->classify($url, $profile),
                'mentions_brand' => $own ? true : null, 'presence_evidence' => $own ? 'owned_domain' : 'not_checked',
                'position' => count($sources) + 1];
        }

        return array_values($sources);
    }

    public function normalizeUrl(#[\SensitiveParameter] string $url): ?string
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }
        $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        if (! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $suffix = $port && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) ? ':'.$port : '';
        // Queries and fragments can carry credentials or tracking identifiers.
        $normalized = $scheme.'://'.$host.$suffix.($parts['path'] ?? '/');

        return strlen($normalized) <= 2048 ? $normalized : null;
    }

    private function classify(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $profile): string
    {
        $domain = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $rules = json_decode(file_get_contents(resource_path('prompts/Vision/Evidence/source-types.ru.json')), true, 64, JSON_THROW_ON_ERROR);
        foreach ($rules['rules'] as $rule) {
            $prefix = $rule['path_prefix'] ?? '/';
            if ($this->domainMatches($domain, $rule['domain'])
                && ($prefix === '/' || $path === $prefix || str_starts_with($path, rtrim($prefix, '/').'/'))) {
                return $rule['type'];
            }
        }
        foreach ($profile['competitors'] ?? [] as $item) {
            if (is_array($item) && is_string($item['domain'] ?? null) && $this->domainMatches($domain, $item['domain'])) {
                return 'competitor_site';
            }
        }

        return 'other';
    }

    public function domainMatches(string $actual, string $expected): bool
    {
        $expected = VisibilityProfile::host($expected);
        $actual = VisibilityProfile::host($actual);

        return $expected !== '' && ($actual === $expected || str_ends_with($actual, '.'.$expected));
    }
}
