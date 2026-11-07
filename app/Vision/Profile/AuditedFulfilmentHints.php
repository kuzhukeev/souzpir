<?php

namespace App\Vision\Profile;

final class AuditedFulfilmentHints
{
    public function extract(array $audit, string $website): array
    {
        $host = strtolower((string) parse_url($website, PHP_URL_HOST));
        if ($host === '') {
            return [];
        }
        $items = [];
        foreach (($audit['pages'] ?? []) as $page) {
            if (! is_array($page) || ! is_string($page['url'] ?? null)
                || strtolower((string) parse_url($page['url'], PHP_URL_HOST)) !== $host
                || ! is_numeric($page['status'] ?? null) || $page['status'] < 200 || $page['status'] >= 300
                || ! is_string($page['fulfilment_evidence'] ?? null)) {
                continue;
            }
            $value = mb_substr(trim($page['fulfilment_evidence']), 0, 180);
            if ($value !== '' && ! in_array($value, array_column($items, 'value'), true)) {
                $items[] = ['label' => 'Данные сайта: доставка или получение', 'value' => $value, 'source' => $page['url']];
            }
            if (count($items) >= 5) {
                break;
            }
        }

        return $items;
    }
}
