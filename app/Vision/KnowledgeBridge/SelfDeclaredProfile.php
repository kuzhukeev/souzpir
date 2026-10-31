<?php

namespace App\Vision\KnowledgeBridge;

use App\Vision\Core\Profiles\Questionnaire;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

/** Private, version-bound projection. It is not a publication or an AI grant. */
final class SelfDeclaredProfile
{
    public function __construct(private readonly Questionnaire $questionnaire) {}

    public function read(#[\SensitiveParameter] Request $request, string $profileId): array
    {
        $profile = $this->questionnaire->profile($request, $profileId);

        return ['profile_id' => $profile['id'], 'version' => $profile['version'], 'checksum' => $profile['checksum'],
            ...self::projectAnswers($profile['answers'])];
    }

    /** Shared strict submitted-profile validation and normalized, explicitly unverified projection. */
    public static function projectAnswers(#[\SensitiveParameter] array $data): array
    {
        $name = self::text($data['step1']['brand_name'] ?? null, true);
        $site = self::text($data['step1']['site'] ?? null);
        $domain = self::domain($site);
        $category = self::text($data['step3']['category'] ?? null);
        $aliases = self::strings($data['step2']['aliases'] ?? [], 50);
        $competitors = $data['step4']['competitors'] ?? [];
        if (! is_array($competitors) || ! array_is_list($competitors) || count($competitors) > 10) {
            throw new InvalidArgumentException('Invalid profile projection.');
        }
        $competition = [];
        foreach ($competitors as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException('Invalid profile projection.');
            }
            $competitorSite = self::text($item['site'] ?? null);
            self::domain($competitorSite);
            $competition[] = ['name' => self::text($item['name'] ?? null, true), 'site' => $competitorSite, 'source' => 'self_declared'];
        }
        $facts = self::strings($data['step5']['facts'] ?? [], 10);

        return [
            'source' => 'self_declared', 'name' => $name, 'site' => $site, 'brand_domain' => $domain, 'domains' => $domain === null ? [] : [$domain], 'category' => $category,
            'aliases' => $aliases, 'competitors' => $competition,
            'facts' => array_map(static fn (string $fact): array => ['value' => $fact, 'source' => 'self_declared', 'verifiable' => false], $facts),
        ];
    }

    /** No suitable self-declared data grant exists yet; never hand this projection to an AI adapter. */
    public function forExternalAi(#[\SensitiveParameter] Request $request, string $profileId): never
    {
        throw new LogicException('Self-declared profile is not authorized for AI.');
    }

    private static function domain(#[\SensitiveParameter] ?string $site): ?string
    {
        if ($site === null) {
            return null;
        }
        $parts = parse_url($site);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Invalid profile projection.');
        }
        $domain = idn_to_ascii(strtolower(rtrim($parts['host'], '.')), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (! is_string($domain) || strlen($domain) > 253 || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException('Invalid profile projection.');
        }

        return $domain;
    }

    private static function text(#[\SensitiveParameter] mixed $value, bool $required = false): ?string
    {
        if ($value === null && ! $required) {
            return null;
        }
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 2000 || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Invalid profile projection.');
        }

        return trim($value);
    }

    private static function strings(#[\SensitiveParameter] mixed $value, int $maximum): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximum) {
            throw new InvalidArgumentException('Invalid profile projection.');
        }

        return array_map(static fn (#[\SensitiveParameter] $item): string => self::text($item, true), $value);
    }
}
