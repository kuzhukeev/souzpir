<?php

namespace App\Vision\Core;

final class VisibilityMetrics
{
    /** Calculate from the planned grid, never the number of returned answers. */
    public function calculate(array $slots, array $providers, int $questions, int $runs): array
    {
        if ($questions < 1 || $runs < 1 || $providers === []) {
            throw new \InvalidArgumentException('A non-empty planned observation grid is required.');
        }
        $grid = [];
        foreach ($slots as $slot) {
            $key = $this->key($slot);
            if (isset($grid[$key])) {
                throw new \InvalidArgumentException('Duplicate observation slot.');
            }
            $grid[$key] = $slot;
        }
        $result = $all = $legacy = [];
        foreach ($providers as $provider) {
            $cells = $complete = [];
            for ($q = 1; $q <= $questions; $q++) {
                $question = [];
                for ($k = 1; $k <= $runs; $k++) {
                    $slot = $grid[$provider.':'.$q.':'.$k] ?? ['provider' => $provider, 'question_n' => $q, 'run_no' => $k, 'status' => 'not_checked'];
                    $slot['valid'] = $this->valid($slot);
                    $slot['classification'] = $this->classification($slot);
                    $cells[] = $slot;
                    $question[] = $slot;
                }
                if (count(array_filter($question, fn (array $s): bool => $this->decisive($s))) === $runs) {
                    $complete[$q] = $question;
                }
            }
            $stats = $this->statistics($cells);
            $perRun = [];
            $stable = $ever = 0;
            foreach ($complete as $question) {
                $positives = 0;
                foreach ($question as $slot) {
                    $positive = $this->confirmed($slot);
                    $perRun[$slot['run_no']] = ($perRun[$slot['run_no']] ?? 0) + (int) $positive;
                    $positives += (int) $positive;
                }
                $stable += (int) ($positives === $runs);
                $ever += (int) ($positives > 0);
            }
            $rates = array_map(fn (int $n): ?float => $this->percent($n, count($complete)), $perRun);
            $result[$provider] = $stats + [
                'complete_questions' => count($complete),
                'observed_run_range' => $rates ? [min($rates), max($rates)] : null,
                'run_rates_on_complete_questions' => $rates,
                'stable_mention_rate' => $this->percent($stable, count($complete)),
                'ever_mentioned_rate' => $this->percent($ever, count($complete)),
                'range_label' => 'Наблюдаемый разброс повторов, не доверительный интервал',
            ];
            $legacy[] = ['cells' => array_map(fn (array $s): array => ['weight' => 1 / ($questions * $runs), 'valid' => $this->decisive($s), 'positive' => $this->confirmed($s)], $cells)];
            array_push($all, ...$cells);
        }
        $panel = $this->statistics($all);
        $rates = array_values(array_filter(array_column($result, 'mention_rate'), fn ($value): bool => $value !== null));
        $panel['micro_mention_rate'] = $panel['mention_rate'];
        $panel['macro_mention_rate'] = $rates ? round(array_sum($rates) / count($rates), 2) : null;
        $panel['available_providers'] = count($rates);
        $panel['total_providers'] = count($providers);

        $entities = $this->entities($all);
        $companyMentions = $panel['mentioned'] + array_sum(array_column(array_filter($entities, fn (array $entity): bool => ! $entity['is_brand'] && (! isset($entity['relevance']) || $entity['relevance'] === 'confirmed')), 'answers'));
        $completeMentions = count(array_filter($all, fn (array $slot): bool => $slot['valid'] && (($slot['mentions_complete'] ?? false) !== true || ! $this->decisive($slot)))) === 0;
        foreach ($entities as &$entity) {
            $entity['share_of_voice'] = ! $completeMentions || ($entity['relevance'] ?? null) === 'candidate' ? null : $this->percent($entity['answers'], $companyMentions);
        }
        unset($entity);
        $panel['share_of_voice'] = $completeMentions ? $this->percent($panel['mentioned'], $companyMentions) : null;
        $panel['share_of_voice_complete'] = $completeMentions;
        $panel['share_of_voice_denominator'] = $completeMentions ? $companyMentions : null;
        $panel['observed_company_mentions'] = $companyMentions;
        $intents = [];
        $questionsById = [];
        foreach ($all as $slot) {
            $intents[$slot['intent'] ?? 'choose'][] = $slot;
            $questionsById[$slot['qid'] ?? (string) $slot['question_n']][] = $slot;
        }

        return ['providers' => $result, 'panel' => $panel, 'visibility' => Metrics::visibility($legacy, count($providers)),
            'entities' => $entities, 'sources' => $this->sources($all), 'slots' => $all,
            'intents' => array_map(fn (array $group): array => $this->statistics($group) + ['entities' => $this->entities($group)], $intents),
            'questions' => array_map(fn (array $group): array => $this->statistics($group), $questionsById)];
    }

    public function compare(array $previous, array $current, ?string $previousKey, string $currentKey): array
    {
        if ($previousKey === null) {
            return ['status' => 'baseline', 'paired_slots' => 0, 'coverage' => 0, 'delta' => null, 'reason' => 'Первый опубликованный замер серии'];
        }
        if (! hash_equals($previousKey, $currentKey)) {
            return ['status' => 'series_break', 'paired_slots' => 0, 'coverage' => 0, 'delta' => null, 'reason' => 'Изменился профиль, вопросы, модели или протокол наблюдения'];
        }
        $before = [];
        foreach ($previous as $slot) {
            if ($this->decisive($slot)) {
                $before[$this->comparisonKey($slot)] = $slot;
            }
        }
        $old = $new = 0;
        $pairs = $providers = [];
        foreach ($current as $slot) {
            $provider = $slot['provider'];
            $providers[$provider] ??= ['planned' => 0, 'paired_slots' => 0, 'mentioned_before' => 0, 'mentioned_after' => 0];
            $providers[$provider]['planned']++;
            $key = $this->comparisonKey($slot);
            if ($this->decisive($slot) && isset($before[$key])) {
                $pairs[] = $key;
                $old += (int) $this->confirmed($before[$key]);
                $new += (int) $this->confirmed($slot);
                $providers[$provider]['paired_slots']++;
                $providers[$provider]['mentioned_before'] += (int) $this->confirmed($before[$key]);
                $providers[$provider]['mentioned_after'] += (int) $this->confirmed($slot);
            }
        }
        $count = count($pairs);
        foreach ($providers as &$provider) {
            $paired = $provider['paired_slots'];
            $provider['coverage'] = $this->percent($paired, $provider['planned']);
            $provider['before'] = $this->percent($provider['mentioned_before'], $paired);
            $provider['after'] = $this->percent($provider['mentioned_after'], $paired);
            $provider['delta'] = $paired ? round(100 * ($provider['mentioned_after'] - $provider['mentioned_before']) / $paired, 2) : null;
        }
        unset($provider);

        $delta = $count ? round(100 * ($new - $old) / $count, 2) : null;

        return ['status' => 'paired', 'paired_slots' => $count, 'slot_keys' => $pairs, 'providers' => $providers, 'coverage' => $this->percent($count, count($current)), 'before' => $this->percent($old, $count), 'after' => $this->percent($new, $count), 'delta' => $delta,
            'below_threshold' => $delta !== null && abs($delta) < 5, 'change_label' => $delta !== null && abs($delta) < 5 ? 'Изменение меньше 5 п.п.' : null];
    }

    public function valid(array $slot): bool
    {
        return ($slot['status'] ?? '') === 'ok' && ($slot['protocol_valid'] ?? true) && isset($slot['fact']) && is_bool($slot['fact']['brand_mentioned'] ?? null)
            && ($slot['fact']['mention_status'] ?? '') !== 'unverified';
    }

    private function decisive(array $slot): bool
    {
        if (! $this->valid($slot)) {
            return false;
        }
        $status = $slot['fact']['mention_status'] ?? (($slot['fact']['brand_mentioned'] ?? false) ? 'confirmed' : 'not_mentioned');

        return in_array($status, ['confirmed', 'not_mentioned'], true);
    }

    private function confirmed(array $slot): bool
    {
        return ($slot['fact']['mention_status'] ?? (($slot['fact']['brand_mentioned'] ?? false) ? 'confirmed' : 'not_mentioned')) === 'confirmed';
    }

    private function classification(array $slot): string
    {
        if ($slot['valid']) {
            return 'ok';
        }
        if (($slot['status'] ?? '') === 'ok') {
            return ($slot['protocol_valid'] ?? true) ? 'parse_failed' : 'protocol_invalid';
        }

        return in_array($slot['status'] ?? '', ['failed', 'uncertain', 'not_checked'], true) ? $slot['status'] : 'not_checked';
    }

    private function statistics(array $slots): array
    {
        $counts = array_fill_keys(['ok', 'parse_failed', 'protocol_invalid', 'failed', 'not_checked', 'uncertain'], 0);
        $search = array_fill_keys(['requested', 'confirmed', 'not_used', 'unknown'], 0);
        $valid = $decisive = $mentioned = $recommended = $recommendedKnown = $possible = 0;
        $positions = $tones = [];
        foreach ($slots as $slot) {
            $counts[$slot['classification']]++;
            $search['requested'] += (int) ($slot['search_requested'] ?? false);
            $search[match ($slot['search_used'] ?? null) {
                true => 'confirmed', false => 'not_used', default => 'unknown'
            }]++;
            if (! $slot['valid']) {
                continue;
            }
            $valid++;
            $decisive += (int) $this->decisive($slot);
            $fact = $slot['fact'];
            $confirmed = ($fact['mention_status'] ?? ($fact['brand_mentioned'] ? 'confirmed' : 'not_mentioned')) === 'confirmed';
            $possible += (int) (($fact['mention_status'] ?? '') === 'possible');
            $mentioned += (int) $confirmed;
            if (is_bool($fact['brand_recommended'] ?? null)) {
                $recommendedKnown++;
                $recommended += (int) ($confirmed && $fact['brand_recommended']);
            }
            if ($confirmed) {
                if (is_numeric($fact['brand_position'] ?? null) && $fact['brand_position'] > 0) {
                    $positions[] = (int) $fact['brand_position'];
                }
                if (isset($fact['tone'])) {
                    $tones[$fact['tone']] = ($tones[$fact['tone']] ?? 0) + 1;
                }
            }
        }
        $planned = count($slots);

        return ['planned' => $planned, 'valid' => $valid, 'mentioned' => $mentioned, 'possible' => $possible, 'possible_rate' => $this->percent($possible, $valid), 'unverified' => $planned - $valid,
            'recommended' => $recommended, 'recommendation_denominator' => $recommendedKnown, 'mention_denominator' => $decisive, 'coverage' => $this->percent($valid, $planned), 'mention_rate' => $this->percent($mentioned, $decisive), 'recommended_rate' => $this->percent($recommended, $recommendedKnown), 'position_average' => $positions ? round(array_sum($positions) / count($positions), 2) : null, 'position_denominator' => count($positions), 'tones' => $tones, 'tone_denominator' => array_sum($tones), 'counts' => $counts, 'search' => $search, 'unknown_bounds' => ['lower' => $this->percent($mentioned, $planned), 'upper' => $this->percent($mentioned + ($valid - $decisive) + $planned - $valid, $planned), 'label' => 'Границы с учётом неизвестных результатов']];
    }

    private function entities(array $slots): array
    {
        $entities = [];
        foreach ($slots as $slot) {
            if (! $slot['valid']) {
                continue;
            }
            $seen = [];
            foreach ($slot['mentions'] ?? [] as $mention) {
                if (($mention['ambiguous_brand'] ?? false) || (($mention['is_brand'] ?? false) && ($slot['fact']['mention_status'] ?? 'confirmed') !== 'confirmed')) {
                    continue;
                }
                $key = $mention['entity_id'] ?? mb_strtolower($mention['name']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $entities[$key] ??= ['name' => $mention['name'], 'role' => null, 'role_counts' => [], 'is_brand' => false, 'answers' => 0, '_seen_slots' => [],
                    ...($mention['entity_id'] ?? null ? ['entity_id' => $mention['entity_id']] : []),
                    ...(isset($mention['relevance']) ? ['relevance' => $mention['relevance'], 'relevance_reasons' => $mention['relevance_reasons'] ?? []] : [])];
                $entities[$key]['is_brand'] = $entities[$key]['is_brand'] || ($mention['is_brand'] ?? false);
                $entities[$key]['answers']++;
                $entities[$key]['_seen_slots'][$this->key($slot)] = true;
                $role = $mention['role'] ?? null;
                if (in_array($role, ['manufacturer', 'supplier', 'marketplace', 'aggregator', 'other'], true)) {
                    $entities[$key]['role_counts'][$role] = ($entities[$key]['role_counts'][$role] ?? 0) + 1;
                }
            }
        }
        foreach ($entities as &$entity) {
            $denominator = count(array_filter($slots, fn (array $slot): bool => $slot['valid'] && (($slot['mentions_complete'] ?? false) === true || isset($entity['_seen_slots'][$this->key($slot)]))));
            unset($entity['_seen_slots']);
            $entity['frequency'] = $this->percent($entity['answers'], $denominator);
            $entity['denominator'] = $denominator;
            ksort($entity['role_counts'], SORT_STRING);
            $knownRoles = array_diff_key($entity['role_counts'], ['other' => true]);
            if ($knownRoles) {
                $leaders = array_keys($knownRoles, max($knownRoles), true);
                $entity['role'] = count($leaders) === 1 ? $leaders[0] : null;
            } elseif (isset($entity['role_counts']['other'])) {
                $entity['role'] = 'other';
            }
            $entity['role_conflict'] = count($knownRoles) > 1;
        }
        unset($entity);
        usort($entities, fn (array $a, array $b): int => ($b['answers'] <=> $a['answers']) ?: strcmp($a['name'], $b['name']));

        return $entities;
    }

    private function sources(array $slots): array
    {
        $domains = [];
        foreach ($slots as $slot) {
            if (! $slot['valid']) {
                continue;
            }
            $seen = [];
            foreach ($slot['sources'] ?? [] as $source) {
                $url = $source['url'] ?? null;
                $domain = $source['domain'] ?? (is_string($url) ? parse_url($url, PHP_URL_HOST) : null);
                if (! is_string($domain) || $domain === '' || ! is_string($url) || $url === '') {
                    continue;
                }
                $type = array_key_exists('source_type', $source) ? $source['source_type'] : ($source['type'] ?? null);
                $presence = array_key_exists('mentions_brand', $source) ? $source['mentions_brand'] : ($source['presence'] ?? null);
                $evidence = $source['presence_evidence'] ?? null;
                $domains[$domain] ??= ['domain' => $domain, 'type' => null, 'urls' => [], 'answers' => 0,
                    'presence' => null, 'presence_evidence' => [], '_types' => [], '_presence' => []];
                $domains[$domain]['urls'][$url] = true;
                if (is_string($type) && $type !== '') {
                    $domains[$domain]['_types'][$type] = true;
                }
                if (is_bool($presence)) {
                    $domains[$domain]['_presence'][$presence ? 'yes' : 'no'] = true;
                }
                if (is_string($evidence) && $evidence !== '') {
                    $domains[$domain]['presence_evidence'][$evidence] = true;
                }
                if (! isset($seen[$domain])) {
                    $domains[$domain]['answers']++;
                    $seen[$domain] = true;
                }
            }
        }
        foreach ($domains as &$domain) {
            $types = array_keys($domain['_types']);
            $presence = array_keys($domain['_presence']);
            $domain['type'] = count($types) === 1 ? $types[0] : null;
            $domain['type_conflict'] = count($types) > 1;
            $domain['presence'] = count($presence) === 1 ? $presence[0] === 'yes' : null;
            $domain['presence_conflict'] = count($presence) > 1;
            $domain['presence_evidence'] = array_keys($domain['presence_evidence']);
            $domain['url_count'] = count($domain['urls']);
            $domain['urls'] = array_keys($domain['urls']);
            unset($domain['_types'], $domain['_presence']);
        }
        unset($domain);
        usort($domains, fn (array $a, array $b): int => $b['answers'] <=> $a['answers']);

        return $domains;
    }

    private function percent(int $numerator, int $denominator): ?float
    {
        return $denominator ? round(100 * $numerator / $denominator, 2) : null;
    }

    private function key(array $slot): string
    {
        return $slot['provider'].':'.$slot['question_n'].':'.$slot['run_no'];
    }

    private function comparisonKey(array $slot): string
    {
        return $slot['provider'].':'.($slot['qid'] ?? $slot['question_n']).':'.$slot['run_no'];
    }
}
