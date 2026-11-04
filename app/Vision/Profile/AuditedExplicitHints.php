<?php

namespace App\Vision\Profile;

final class AuditedExplicitHints
{
    public function extract(array $audit, string $website, int $step): array
    {
        $prefix = match ($step) {
            3 => 'Типы покупателей',
            4 => 'Конкуренты',
            7 => 'Вопросы клиентов',
            default => null,
        };
        $host = strtolower((string) parse_url($website, PHP_URL_HOST));
        if ($prefix === null || $host === '') {
            return [];
        }
        $items = [];
        foreach (($audit['pages'] ?? []) as $page) {
            if (! is_array($page) || ! is_string($page['url'] ?? null) || strtolower((string) parse_url($page['url'], PHP_URL_HOST)) !== $host
                || ! is_numeric($page['status'] ?? null) || $page['status'] < 200 || $page['status'] >= 300 || ! is_array($page['headings'] ?? null)) {
                continue;
            }
            foreach ($page['headings'] as $heading) {
                if (! is_string($heading) || ! preg_match('/^'.preg_quote($prefix, '/').':\h*(.+)$/ui', trim($heading), $matches)) {
                    continue;
                }
                foreach (preg_split('/\s*[;,]\s*/u', $matches[1]) ?: [] as $value) {
                    $value = mb_substr(trim($value), 0, 180);
                    if ($value === '' || ($step === 7 && ! str_ends_with($value, '?')) || in_array($value, array_column($items, 'value'), true)) {
                        continue;
                    }
                    $item = ['label' => 'Данные сайта: '.$prefix, 'value' => $value, 'source' => $page['url']];
                    if ($step === 3) {
                        $item['kind'] = 'customer_type';
                    }
                    $items[] = $item;
                    if (count($items) === 5) {
                        return $items;
                    }
                }
            }
        }

        return $items;
    }
}
