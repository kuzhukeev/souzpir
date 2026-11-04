<?php

namespace App\Vision\Competitors;

final class SourceRanking
{
    /** @return list<array{domain: string, answers: int}> */
    public function forSlots(iterable $slots): array
    {
        $counts = [];
        foreach ($slots as $slot) {
            $domains = [];
            foreach ($slot['sources'] ?? [] as $source) {
                $domain = is_array($source) ? ($source['domain'] ?? null) : null;
                if (is_string($domain) && trim($domain) !== '') {
                    $domains[$domain] = true;
                }
            }
            foreach (array_keys($domains) as $domain) {
                $counts[$domain] = ($counts[$domain] ?? 0) + 1;
            }
        }
        $result = [];
        foreach ($counts as $domain => $answers) {
            $result[] = ['domain' => $domain, 'answers' => $answers];
        }
        usort($result, fn ($a, $b) => ($b['answers'] <=> $a['answers']) ?: strcmp($a['domain'], $b['domain']));

        return array_slice($result, 0, 3);
    }
}
