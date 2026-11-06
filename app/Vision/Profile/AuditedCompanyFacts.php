<?php

namespace App\Vision\Profile;

final class AuditedCompanyFacts
{
    public function extract(array $audit, string $website): array
    {
        $host = strtolower((string) parse_url($website, PHP_URL_HOST));
        if ($host === '') {
            return [];
        }
        $items = [];
        foreach ($audit['pages'] ?? [] as $page) {
            if (! is_array($page) || ! is_string($page['url'] ?? null)
                || strtolower((string) parse_url($page['url'], PHP_URL_HOST)) !== $host
                || ! is_numeric($page['status'] ?? null) || $page['status'] < 200 || $page['status'] >= 300
                || ! is_array($page['headings'] ?? null)) {
                continue;
            }
            foreach ($page['headings'] as $heading) {
                if (! is_string($heading) || ! preg_match('/^(Адрес|Телефон|Год основания|Сертификаты|Доставка):\h*(.+)$/ui', trim($heading), $matches)) {
                    continue;
                }
                $value = mb_substr(trim($matches[2]), 0, 180);
                if ($value === '' || in_array($value, array_column($items, 'value'), true)) {
                    continue;
                }
                $items[] = ['label' => 'Данные сайта: '.$matches[1], 'value' => $value, 'source' => $page['url'], 'kind' => 'site_fact'];
                if (count($items) >= 5) {
                    return $items;
                }
            }
        }

        return $items;
    }
}
