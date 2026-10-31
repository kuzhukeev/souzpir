<?php

namespace App\Vision\Evidence;

/** Identity evidence must occur literally in the stored answer; model assertions alone prove nothing. */
final class ConfirmationValidator
{
    public function __construct(private MentionConfirmer $aliases) {}

    /** @return array{status: string, evidence: ?string} */
    public function validate(#[\SensitiveParameter] string $text, #[\SensitiveParameter] array $profile, #[\SensitiveParameter] ?array $modelResult, #[\SensitiveParameter] ?string $entityName = null): array
    {
        if ($entityName === null || ! app(ExactMentionVerifier::class)->inText($text, $entityName, $profile)) {
            return ['status' => 'unknown', 'evidence' => null];
        }
        $matched = [];
        foreach (VisibilityProfile::recognitionAliases($profile) as $alias) {
            if (! $this->aliases->contains($text, $alias)) {
                continue;
            }
            if (! $this->aliases->isAmbiguous($alias, $profile)) {
                return ['status' => 'confirmed', 'evidence' => mb_substr($alias, 0, 200)];
            }
            $matched[] = $alias;
        }
        if ($matched === []) {
            return ['status' => 'unknown', 'evidence' => null];
        }
        if (! is_array($modelResult) || ($modelResult['decision'] ?? null) !== 'confirmed'
            || ! is_string($modelResult['evidence'] ?? null) || $modelResult['evidence'] === ''
            || mb_strlen($modelResult['evidence']) > 200 || ! is_array($modelResult['matched_facts'] ?? null)
            || ! array_is_list($modelResult['matched_facts']) || count($modelResult['matched_facts']) > 5
            || array_diff(array_keys($modelResult), ['decision', 'evidence', 'matched_facts']) !== []
            || count(array_filter($modelResult['matched_facts'], fn (mixed $fact): bool => is_string($fact) && mb_strlen($fact) <= 120)) !== count($modelResult['matched_facts'])
            || ! str_contains($this->normalize($text), $this->normalize($modelResult['evidence']))) {
            return ['status' => 'unknown', 'evidence' => null];
        }
        $quote = $modelResult['evidence'];
        if (preg_match('/(?<!\p{L})(?:не|нет|без|кроме|not|no|without)(?!\p{L})/iu', $quote)) {
            return ['status' => 'unknown', 'evidence' => null];
        }
        if (! collect($matched)->contains(fn (string $alias): bool => $this->aliases->contains($quote, $alias))) {
            return ['status' => 'unknown', 'evidence' => null];
        }
        $facts = [];
        $distinctive = false;
        foreach ($profile['facts'] ?? [] as $fact) {
            if (! is_array($fact) || ($fact['verifiable'] ?? false) !== true
                || ! is_string($fact['value'] ?? null) || ! is_string($fact['topic'] ?? null)
                || mb_strlen($fact['value']) < 3 || ! $this->aliases->contains($quote, $fact['value'])) {
                continue;
            }
            $identified = collect($modelResult['matched_facts'])->contains(
                fn (mixed $match): bool => is_string($match) && $this->aliases->contains($match, $fact['value'])
            );
            if (! $identified) {
                continue;
            }
            $facts[$fact['topic']] = $this->normalize($fact['value']);
            $distinctive = $distinctive || $this->distinctiveTopic($fact['topic']);
        }
        if (count(array_unique($facts)) < 2 || ! $distinctive) {
            return ['status' => 'unknown', 'evidence' => null];
        }

        return ['status' => 'confirmed', 'evidence' => $quote];
    }

    private function distinctiveTopic(string $topic): bool
    {
        return in_array($this->normalize($topic), [
            'address', 'phone', 'legal_name', 'lead_time', 'warranty', 'founded', 'price_from',
            'min_order', 'certificates', 'materials', 'schedule', 'payment', 'integrations', 'support',
            'адрес', 'телефон', 'юридическое название', 'юридическое лицо', 'срок изготовления',
            'срок выполнения', 'срок поставки', 'гарантия', 'год основания', 'дата основания',
            'цена от', 'минимальный заказ', 'сертификаты', 'материалы', 'график работы',
            'режим работы', 'оплата', 'интеграции', 'поддержка',
        ], true);
    }

    private function normalize(#[\SensitiveParameter] string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace('ё', 'е', mb_strtolower($value))) ?? '');
    }
}
