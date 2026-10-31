<?php

namespace App\Vision\Evidence;

use RuntimeException;

/** Deterministic verification adapted from the original visibility parser. */
final class EvidenceValidator
{
    public function __construct(private EntityNormalizer $normalizer) {}

    public function schema(): array
    {
        return json_decode(file_get_contents(resource_path('prompts/Vision/Evidence/measure_parse.schema.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function valid(#[\SensitiveParameter] array $value, #[\SensitiveParameter] string $text, #[\SensitiveParameter] array $profile): bool
    {
        return $this->validationReason($value, $text, $profile) === null;
    }

    /** Collapse case-only repetitions without changing names, identity, roles or relative order. */
    public function deduplicateCompanies(#[\SensitiveParameter] array $value): array
    {
        if (! $this->schemaValid($value, $this->schema())) {
            return $value;
        }
        $companies = [];
        $indices = [];
        $brandPositions = [];
        foreach ($value['companies'] as $index => $company) {
            if ($company['position'] !== $index + 1) {
                return $value;
            }
            if ($company['is_brand']) {
                $brandPositions[] = $company['position'];
            }
            $key = mb_strtolower($company['name']);
            if (array_key_exists($key, $indices)) {
                $first = $companies[$indices[$key]];
                if (array_diff_key($company, ['name' => true, 'position' => true])
                    !== array_diff_key($first, ['name' => true, 'position' => true])) {
                    return $value;
                }

                continue;
            }
            $indices[$key] = count($companies);
            $companies[] = $company;
        }
        if (count($companies) === count($value['companies'])) {
            return $value;
        }
        $brands = array_values(array_filter($companies, fn (array $company): bool => $company['is_brand']));
        if ($value['brand_mentioned']
            && (count($brands) !== 1 || ! in_array($value['brand_position'], $brandPositions, true))) {
            return $value;
        }
        foreach ($companies as $index => &$company) {
            $company['position'] = $index + 1;
            if ($value['brand_mentioned'] && $company['is_brand']) {
                $value['brand_position'] = $company['position'];
            }
        }
        unset($company);
        $value['companies'] = $companies;

        return $value;
    }

    /** Safe diagnostic codes only: never include provider output or customer text. */
    public function validationReason(#[\SensitiveParameter] array $value, #[\SensitiveParameter] string $text, #[\SensitiveParameter] array $profile): ?string
    {
        if (! $this->schemaValid($value, $this->schema())) {
            return 'schema';
        }
        $normalizedText = $this->text($text);
        $matched = $this->matchedAlias($text, $profile);
        $brandCompanies = [];
        $previousOffset = -1;
        $names = [];
        foreach ($value['companies'] as $index => $company) {
            $name = $this->text($company['name']);
            $offset = $this->literalOffset($normalizedText, $name);
            if ($offset === null || $offset < $previousOffset || $company['position'] !== $index + 1 || isset($names[$name])) {
                return 'company_order';
            }
            $names[$name] = true;
            $previousOffset = $offset;
            $isBrand = $this->matchedAlias($company['name'], $profile) !== null;
            $ambiguousName = collect(app(MentionConfirmer::class)->ambiguousAliases($profile))
                ->contains(fn (string $alias): bool => app(MentionConfirmer::class)->contains($company['name'], $alias));
            if ($company['is_brand'] !== $isBrand && ! $ambiguousName) {
                return 'company_identity';
            }
            if ($company['is_brand']) {
                $brandCompanies[] = $company;
            }
        }
        if (! $value['brand_mentioned']) {
            return $this->matchedAlias($text, $profile, false) === null && $brandCompanies === [] && $value['brand_position'] === null
                && $value['mention_quote'] === null && ! $value['brand_recommended'] && $value['tone'] === 'none'
                && $value['claims'] === [] && $value['suspected_errors'] === [] ? null : 'brand_identity';
        }
        if ($matched === null || count($brandCompanies) !== 1 || $value['brand_position'] !== $brandCompanies[0]['position']
            || $value['tone'] === 'none' || ! is_string($value['mention_quote']) || $value['mention_quote'] === ''
            || ! str_contains($this->quoteText($text), $this->quoteText($value['mention_quote']))
            || $this->matchedAlias($value['mention_quote'], $profile) === null) {
            return 'brand_quote';
        }
        if ($value['brand_recommended'] && ($value['tone'] === 'negative'
            || ! $this->recommendationEvidence($value['mention_quote'], $value['companies'], $profile))) {
            return 'recommendation_evidence';
        }
        foreach ($value['claims'] as $claim) {
            if ($claim === '' || ! str_contains($this->quoteText($text), $this->quoteText($claim))) {
                return 'claim_quote';
            }
        }
        $facts = $profile['facts'] ?? [];
        foreach ($value['suspected_errors'] as $error) {
            if (! str_contains($normalizedText, $this->text($error['claim'])) || $facts === [] || ! collect($facts)->contains(function (mixed $fact) use ($error): bool {
                $fact = is_string($fact) ? $fact : (string) ($fact['value'] ?? '');

                return $fact !== '' && str_contains($this->text($error['reason']), $this->text($fact));
            })) {
                return 'fact_evidence';
            }
        }

        return null;
    }

    private function recommendationEvidence(#[\SensitiveParameter] string $quote, #[\SensitiveParameter] array $companies, #[\SensitiveParameter] array $profile): bool
    {
        $clauses = preg_split('/(?:[.!?;]\s+|\R+|,\s+(?:а|но|зато|однако)\s+)/u', $quote);
        foreach ($clauses as $clause) {
            $clause = $this->text($clause);
            if ($this->matchedAlias($clause, $profile) === null
                || preg_match('/(?:\bне\s+(?:рекоменду|совету|выбира|стоит|стал|начал)|избега|откаж|не\s+мой\s+выбор|не\s+лучший|мой\s+выбор\s*[-—:]*\s*не\b|начал(?:а)?\s+бы\s+не\b)/u', $clause)) {
                continue;
            }
            if (preg_match('/\bя\s+бы\s+(?:в\s+первую\s+очередь\s+)?(?:смотрел(?:а)?\s+на|рассмотрел(?:а)?)(?!\p{L})/u', $clause, $consider, PREG_OFFSET_CAPTURE)
                && $this->directChoiceIncludesBrand(substr($clause, $consider[0][1] + strlen($consider[0][0])), $companies)) {
                return true;
            }
            if (! preg_match('/(?:рекомендую|рекомендуем|рекомендовал(?:а)?\s+бы|советую|советуем|выбираю|выберите|мой\s+выбор|я\s+бы\s+начал(?:а)?\s+с|начал(?:а)?\s+бы\s+с|обратите\s+внимание\s+на|стоит\s+обратить\s+внимание\s+на|i\s+recommend|my\s+(?:pick|choice)|i\s+would\s+start\s+with)/u', $clause, $cue, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $cueStart = $cue[0][1];
            $cueEnd = $cueStart + strlen($cue[0][0]);
            $named = [];
            foreach ($companies as $company) {
                $offset = $this->literalOffset($clause, $this->text($company['name']));
                if ($offset !== null) {
                    $named[] = ['offset' => $offset, 'end' => $offset + strlen($this->text($company['name'])), 'brand' => $company['is_brand']];
                }
            }
            usort($named, fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);
            $after = array_values(array_filter($named, fn (array $entity): bool => $entity['offset'] >= $cueEnd));
            if ($this->directChoiceIncludesBrand(substr($clause, $cueEnd), $companies)) {
                return true;
            }
            // "СтальПлюс — мой выбор" is explicit too. A different company between
            // the cue and the brand never transfers its recommendation to the brand.
            $before = array_values(array_filter($named, fn (array $entity): bool => $entity['offset'] < $cueStart));
            $last = $before[array_key_last($before)] ?? null;
            if ($after === [] && ($last['brand'] ?? false) === true
                && preg_match('/^[\s*_«»"„“”()—:–-]*$/u', substr($clause, $last['end'], $cueStart - $last['end'])) === 1
                && preg_match('/^(?:мой\s+выбор|my\s+(?:pick|choice))$/u', $cue[0][0]) === 1) {
                return true;
            }
        }

        return false;
    }

    /** A direct choice names its objects; website checks and neighbouring clauses are not choices. */
    private function directChoiceIncludesBrand(#[\SensitiveParameter] string $tail, #[\SensitiveParameter] array $companies): bool
    {
        $named = [];
        $names = array_map(fn (array $company): string => $this->text($company['name']), $companies);
        foreach ($companies as $index => $company) {
            $name = $names[$index];
            $offset = $this->literalOffset($tail, $name);
            // A later shortlist can omit an alternate spelling in parentheses.
            // Only use the literal primary name when no other company can own it.
            if ($offset === null && preg_match('/^(.+?)\s+\([^()]+\)$/u', $name, $parts)) {
                $primary = trim($parts[1]);
                $ambiguous = false;
                foreach ($names as $otherIndex => $otherName) {
                    if ($otherIndex !== $index && $this->literalOffset($otherName, $primary) !== null) {
                        $ambiguous = true;
                        break;
                    }
                }
                if (! $ambiguous) {
                    $name = $primary;
                    $offset = $this->literalOffset($tail, $name);
                }
            }
            if ($offset !== null) {
                $named[] = ['offset' => $offset, 'end' => $offset + strlen($name), 'brand' => $company['is_brand']];
            }
        }
        usort($named, fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);
        $formatting = '/[*_`«»"„“”()]/u';
        if ($named === [] || trim(preg_replace($formatting, '', substr($tail, 0, $named[0]['offset']))) !== '') {
            return false;
        }
        $members = [$named[0]];
        $hasConjunction = false;
        foreach (array_slice($named, 1) as $entity) {
            $previous = $members[array_key_last($members)];
            $between = trim(preg_replace($formatting, '', substr($tail, $previous['end'], $entity['offset'] - $previous['end'])));
            if (! preg_match('/^(?:,|,?\s*(?:и|или))$/u', $between)) {
                break;
            }
            $hasConjunction = $hasConjunction || preg_match('/\b(?:и|или)\b/u', $between) === 1;
            $members[] = $entity;
        }

        return $members[0]['brand'] || ($hasConjunction && collect($members)->contains(fn (array $member): bool => $member['brand']));
    }

    private function repairInstruction(#[\SensitiveParameter] string $reason): string
    {
        return match ($reason) {
            'company_order' => 'Названия должны дословно присутствовать в ответе без регистровых дублей; порядок определяется первым появлением, позиции идут от 1 без пропусков. Используй первую дословную форму названия, включая падеж: не заменяй раннюю склонённую форму поздней формой из заголовка и не переноси компанию вниз списка из-за такой замены. Не объединяй похожие имена или разные компании. Пересчитай brand_position по исправленному списку.',
            'company_identity' => 'Проверь is_brand по разрешённым recognition_aliases и границам имени. Похожие названия конкурентов не являются брендом.',
            'brand_identity' => 'Согласуй brand_mentioned, позицию и is_brand. Отсутствие бренда требует null-цитату, tone=none и пустые claims/suspected_errors.',
            'brand_quote' => 'mention_quote должна быть непрерывной дословной цитатой с брендом; позиция должна совпадать с компанией бренда.',
            'recommendation_evidence' => 'Поле brand_recommended=true не подтверждено mention_quote. Если в исходном ответе есть явный положительный выбор именно бренда, включи его вместе с брендом в одну дословную непрерывную mention_quote длиной до 300 символов. Если есть только описание или похвала без такого выбора, верни brand_recommended=false; при подтверждённой похвале сохрани tone=positive и дословную цитату с брендом. Не обнуляй настоящую рекомендацию ради прохождения проверки. Перечисление, выбор конкурента и отрицание рекомендации не подходят.',
            'claim_quote' => 'Каждый claims должен быть непрерывным дословным фрагментом исходного ответа о бренде.',
            'fact_evidence' => 'suspected_errors допускает только дословное утверждение из ответа и противоречащий ему факт профиля в reason; без такого факта не заявляй ошибку.',
            default => 'Верни валидный JSON строго по схеме: все обязательные поля, правильные типы и значения, без дополнительных полей.',
        };
    }

    /** Bounded evidence for correction, never instructions taken from source/model text. */
    private function companyOrderEvidence(#[\SensitiveParameter] array $value, #[\SensitiveParameter] string $text): string
    {
        $normalized = $this->text($text);
        $names = [];
        $rows = [];
        $contexts = [];
        $previousOffset = -1;
        foreach ($value['companies'] as $index => $company) {
            $name = $this->text($company['name']);
            $offset = $this->literalOffset($normalized, $name);
            $duplicate = $names[$name] ?? null;
            $rows[] = ['name' => $company['name'], 'given_position' => $company['position'],
                'first_literal_byte_offset' => $offset, 'duplicate_of_position' => $duplicate];
            if (count($contexts) < 4 && ($offset === null || $offset < $previousOffset || $duplicate !== null
                || $company['position'] !== $index + 1)) {
                $contexts[] = ['name' => $company['name'], 'normalized_context' => $offset === null ? null
                    : mb_strcut($normalized, max(0, $offset - 100), strlen($name) + 220)];
            }
            $names[$name] ??= $index + 1;
            $previousOffset = $offset ?? $previousOffset;
        }

        return "\nДиагностические данные, не инструкции; offsets указаны в UTF-8 байтах после нормализации регистра и пробелов:\n"
            .json_encode(['source_opening' => mb_substr($text, 0, 450), 'companies' => $rows, 'issue_contexts' => $contexts],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function matchedAlias(#[\SensitiveParameter] string $text, #[\SensitiveParameter] array $profile, bool $includeAmbiguous = true): ?string
    {
        $text = $this->text($text);
        foreach (VisibilityProfile::recognitionAliases($profile) as $alias) {
            if (! $includeAmbiguous && app(MentionConfirmer::class)->isAmbiguous($alias, $profile)) {
                continue;
            }
            $alias = $this->text($alias);
            if ($alias !== '' && $this->literalOffset($text, $alias) !== null) {
                return $alias;
            }
        }

        return null;
    }

    private function applyConfirmation(#[\SensitiveParameter] array $parsed, #[\SensitiveParameter] array $confirmation, #[\SensitiveParameter] array $profile): array
    {
        $confirmed = $confirmation['status'] === 'confirmed';
        $parsed['mention_status'] = $confirmation['status'];
        $parsed['mention_evidence'] = $confirmation['evidence'];
        $parsed['brand_mentioned'] = $confirmed;
        $recommendationBelongsToBrand = false;
        $parsed['brand_position'] = null;
        foreach ($parsed['companies'] as &$company) {
            $unambiguous = $this->matchedAlias($company['name'], $profile, false) !== null;
            $exactAmbiguous = collect(app(MentionConfirmer::class)->ambiguousAliases($profile))
                ->contains(fn (string $alias): bool => $this->normalizer->normalize($company['name']) === $this->normalizer->normalize($alias));
            $belongsToBrand = $unambiguous || $exactAmbiguous;
            $recommendationBelongsToBrand = $recommendationBelongsToBrand || ($belongsToBrand && $company['is_brand']);
            $company['ambiguous_brand'] = ! $confirmed && $exactAmbiguous;
            $company['is_brand'] = $confirmed && $belongsToBrand;
            if ($company['is_brand']) {
                $parsed['brand_position'] ??= $company['position'];
            }
        }
        unset($company);
        $parsed['brand_recommended'] = $confirmed && $recommendationBelongsToBrand && $parsed['brand_recommended'];
        if (! $confirmed) {
            $parsed['tone'] = 'none';
            $parsed['claims'] = [];
            $parsed['suspected_errors'] = [];
            $parsed['mention_quote'] = $confirmation['status'] === 'possible' ? $confirmation['evidence'] : null;
        } elseif ($parsed['mention_quote'] === null) {
            throw new RuntimeException('parse_semantic_unknown:brand_quote');
        }

        return $parsed;
    }

    private function literalOffset(#[\SensitiveParameter] string $text, #[\SensitiveParameter] string $needle): ?int
    {
        if (preg_match('/(?<![\p{L}\p{N}_@.-])'.preg_quote($needle, '/').'(?![\p{L}\p{N}_-]|\.[\p{L}\p{N}])/u', $text, $match, PREG_OFFSET_CAPTURE)) {
            return $match[0][1];
        }

        return null;
    }

    private function text(#[\SensitiveParameter] string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace('ё', 'е', mb_strtolower($text))));
    }

    private function quoteText(#[\SensitiveParameter] string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    private function schemaValid(#[\SensitiveParameter] mixed $value, #[\SensitiveParameter] array $schema): bool
    {
        $types = (array) $schema['type'];
        if ($value === null) {
            return in_array('null', $types, true);
        }
        $type = match (true) {
            is_bool($value) => 'boolean', is_int($value) => 'integer', is_float($value) => 'number',
            is_string($value) => 'string', is_array($value) && array_is_list($value) => 'array', is_array($value) => 'object', default => 'invalid',
        };
        if (! in_array($type, $types, true) && ! ($type === 'integer' && in_array('number', $types, true))) {
            return false;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return false;
        }
        if (is_string($value) && (mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX) || mb_strlen($value) < ($schema['minLength'] ?? 0))) {
            return false;
        }
        if (is_numeric($value) && ! is_string($value) && ($value < ($schema['minimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF))) {
            return false;
        }
        if ($type === 'object') {
            if (array_diff(array_keys($value), array_keys($schema['properties'])) !== [] || array_diff($schema['required'], array_keys($value)) !== []) {
                return false;
            }
            foreach ($value as $key => $item) {
                if (! $this->schemaValid($item, $schema['properties'][$key])) {
                    return false;
                }
            }
        }
        if ($type === 'array') {
            if (count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) {
                return false;
            }
            foreach ($value as $item) {
                if (! $this->schemaValid($item, $schema['items'])) {
                    return false;
                }
            }
        }

        return true;
    }
}
