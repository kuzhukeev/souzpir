<?php

namespace App\Seller\Catalog;

use Illuminate\Validation\ValidationException;

final class CatalogImporter
{
    public function parse(string $path, string $extension): array
    {
        $reader = app(CatalogReader::class);
        $data = $reader->read($path, $extension);
        $rows = $data['rows'];
        $mapping = $reader->suggest($rows[0] ?? []);
        if (! isset($mapping['name'])) {
            throw ValidationException::withMessages(['file' => 'Не найден столбец «Название».']);
        }
        $items = [];
        foreach (array_slice($rows, 1) as $index => $row) {
            if (! array_filter($row, fn ($cell) => trim((string) $cell) !== '')) {
                continue;
            }
            if (in_array($index + 1, $data['category_rows'] ?? [], true)) {
                continue;
            }
            $value = fn (string $key): string => trim((string) ($row[$mapping[$key] ?? -1] ?? ''));
            $name = $value('name');
            if ($name === '' || mb_strlen($name) > 500 || mb_strlen($value('sku')) > 255 || mb_strlen($value('category_path')) > 255) {
                throw ValidationException::withMessages(['file' => 'Проверьте название и текстовые поля в строке '.($index + 2).'.']);
            }
            $item = ['name' => $name, 'sku' => $value('sku') ?: null, 'category' => $value('category_path') ?: null];
            foreach (['price', 'wholesale_price', 'stock'] as $key) {
                $raw = $value($key);
                if ($raw === '') {
                    $item[$key] = null;

                    continue;
                }
                $number = str_replace(["\u{00a0}", ' ', ','], ['', '', '.'], $raw);
                if (! is_numeric($number) || (float) $number < 0 || (float) $number >= 100000000000) {
                    throw ValidationException::withMessages(['file' => 'Некорректное число в строке '.($index + 2).'.']);
                }
                $item[$key] = $number;
            }
            $items[] = $item;
        }
        if (! $items) {
            throw ValidationException::withMessages(['file' => 'В файле нет товарных строк.']);
        }

        return $items;
    }
}
