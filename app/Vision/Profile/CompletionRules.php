<?php

namespace App\Vision\Profile;

use App\Vision\Evidence\VisibilityProfile;
use App\Vision\SiteAudit\PublicUrlPolicy;
use InvalidArgumentException;

/** Submit-only donor requirements. Drafts may be incomplete. */
final class CompletionRules
{
    public function validate(#[\SensitiveParameter] array $answers): void
    {
        $first = $answers['step1'] ?? [];
        $second = $answers['step2'] ?? [];
        $third = $answers['step3'] ?? [];
        $site = is_array($first) ? ($first['site'] ?? null) : null;
        $name = is_array($first) ? ($first['brand_name'] ?? null) : null;
        if (! is_string($site) || parse_url($site, PHP_URL_FRAGMENT) !== null || ! is_string($name) || trim($name) === '' || mb_strlen($name) > 200) {
            throw new InvalidArgumentException('Invalid brand details.');
        }
        // Syntax check only: normalize does not resolve DNS or contact the site.
        app(PublicUrlPolicy::class)->normalize($site);
        $host = strtolower(rtrim((string) parse_url($site, PHP_URL_HOST), '.'));
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (! is_string($ascii) || preg_match('/(^|\.)(localhost|local|localdomain|lan|home|internal|test|invalid|onion|arpa)$/i', $ascii)
            || preg_match('/^(?:[0-9]+|0x[0-9a-f]+)(?:\.(?:[0-9]+|0x[0-9a-f]+))*$/i', $ascii)) {
            throw new InvalidArgumentException('Invalid website.');
        }
        $aliases = is_array($second) ? ($second['aliases'] ?? null) : null;
        if (! is_array($aliases) || ! array_is_list($aliases) || count($aliases) < 2 || count($aliases) > 50) {
            throw new InvalidArgumentException('Invalid aliases.');
        }
        foreach ($aliases as $alias) {
            if (! is_string($alias) || trim($alias) === '' || mb_strlen($alias) > 200 || ! mb_check_encoding($alias, 'UTF-8')) {
                throw new InvalidArgumentException('Invalid aliases.');
            }
        }
        $domain = VisibilityProfile::host($site);
        if (! in_array($domain, array_map(VisibilityProfile::alias(...), $aliases), true)) {
            throw new InvalidArgumentException('Website domain is required among aliases.');
        }
        if (! is_array($third)
            || ! in_array($third['offering'] ?? null, ['goods', 'services', 'software'], true)
            || ! in_array($third['audience'] ?? null, ['b2c', 'b2b', 'both'], true)
            || ! $this->text($third['category'] ?? null, 1, 200)
            || ! $this->text($third['buyer_phrase'] ?? null, 3, 120)
            || ! is_array($third['category_forms'] ?? null)
            || ! $this->text($third['category_forms']['nominative'] ?? null, 1, 200)
            || ! $this->text($third['category_forms']['genitive_plural'] ?? null, 1, 200)) {
            throw new InvalidArgumentException('Invalid offering details.');
        }
        foreach ($aliases as $alias) {
            if (! VisibilityProfile::isAmbiguous($alias, $third)
                && preg_match('~(?<![\p{L}\p{N}])'.preg_quote($alias, '~').'(?![\p{L}\p{N}])~iu', $third['buyer_phrase'])) {
                throw new InvalidArgumentException('Buyer phrase must not contain the brand.');
            }
        }
        foreach (($answers['step4']['competitors'] ?? []) as $competitor) {
            if (is_array($competitor) && is_string($competitor['site'] ?? null) && $competitor['site'] !== ''
                && VisibilityProfile::host($competitor['site']) === $domain) {
                throw new InvalidArgumentException('The brand cannot compete with itself.');
            }
        }
    }

    private function text(mixed $value, int $min, int $max): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen(trim($value)) >= $min && mb_strlen($value) <= $max;
    }
}
