<?php

namespace App\Vision\Evidence;

/** A narrow literal rule: anything semantically ambiguous remains unknown. */
final class RecommendationVerifier
{
    public function verifies(#[\SensitiveParameter] string $text, #[\SensitiveParameter] string $quote, #[\SensitiveParameter] array $profile): bool
    {
        $text = mb_strtolower(str_replace('ё', 'е', $text));
        $quote = mb_strtolower(str_replace('ё', 'е', $quote));
        if (preg_match('/(?<!\p{L})(?:не|нет|без|кроме|избегайте|not|no|without|avoid)(?!\p{L})/iu', $text)) {
            return false;
        }
        foreach (VisibilityProfile::recognitionAliases($profile) as $alias) {
            if (app(MentionConfirmer::class)->isAmbiguous($alias, $profile)) {
                continue;
            }
            $alias = mb_strtolower(str_replace('ё', 'е', $alias));
            if ($alias === '') {
                continue;
            }
            $pattern = '/(?<![\p{L}\p{N}_@.-])(?:рекомендую|рекомендуем|советую|советуем|выбираю|i recommend)\s+[*_«»"„“”]*'
                .preg_quote($alias, '/').'[*_«»"„“”]*(?![\p{L}\p{N}_-]|\.[\p{L}\p{N}])(?=\s*(?:[.!?:;—]|$))/iu';
            if (preg_match($pattern, $quote) && preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }
}
