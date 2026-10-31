<?php

namespace App\Vision\Evidence;

final class MentionConfirmer
{
    public const VERSION = 'mention-confirmation-4';

    /** @return list<string> */
    public function ambiguousAliases(#[\SensitiveParameter] array $profile): array
    {
        $aliases = $profile['ambiguous_aliases'] ?? [];
        foreach ($profile['alias_entries'] ?? [] as $entry) {
            if ($entry['ambiguous'] ?? false) {
                $aliases[] = $entry['value'];
            }
        }
        // Frozen profiles from before the quiz have no ambiguity flags.
        // Apply the same exact-word/category rule without rewriting their data.
        foreach (VisibilityProfile::recognitionAliases($profile) as $alias) {
            if (VisibilityProfile::isAmbiguous($alias, $profile)) {
                $aliases[] = $alias;
            }
        }

        return array_values(array_unique(array_filter(array_map(fn (mixed $value): string => $this->normalize((string) $value), $aliases))));
    }

    public function isAmbiguous(#[\SensitiveParameter] string $alias, #[\SensitiveParameter] array $profile): bool
    {
        return in_array($this->normalize($alias), $this->ambiguousAliases($profile), true);
    }

    public function contains(#[\SensitiveParameter] string $text, #[\SensitiveParameter] string $alias): bool
    {
        $alias = $this->normalize($alias);

        return $alias !== '' && preg_match('/(?<![\p{L}\p{N}_@.-])'.preg_quote($alias, '/').'(?![\p{L}\p{N}_-]|\.[\p{L}\p{N}])/u', $this->normalize($text)) === 1;
    }

    private function normalize(#[\SensitiveParameter] string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace('ё', 'е', mb_strtolower($text))));
    }
}
