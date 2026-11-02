<?php

namespace App\Seller\Catalog;

use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class CatalogReader
{
    /** @return array{sheets:array,rows:array,encoding:string,delimiter:?string} */
    public function read(string $path, string $extension, int $sheet = 0): array
    {
        if (! is_file($path) || filesize($path) > 10 * 1024 * 1024) {
            $this->invalid('Файл каталога должен быть не больше 10 МБ.');
        }

        return match (strtolower($extension)) {
            'csv' => $this->csv($path),
            'xlsx' => $this->xlsx($path, $sheet),
            default => $this->invalid('Поддерживаются CSV и XLSX.'),
        };
    }

    public function suggest(array $header): array
    {
        $aliases = ['name' => ['название', 'наименование', 'номенклатура', 'товар', 'услуга', 'name', 'product'], 'sku' => ['артикул', 'код', 'sku'],
            'price' => ['цена', 'цена руб', 'розница', 'стоимость', 'price'], 'unit' => ['единица', 'ед', 'ед изм', 'ед изм', 'unit'],
            'stock' => ['остаток', 'наличие', 'количество', 'stock', 'quantity'], 'wholesale_price' => ['цена опт', 'оптовая цена', 'wholesale'], 'category_path' => ['категория', 'раздел', 'группа', 'category'], 'description' => ['описание', 'description']];
        $mapping = [];
        foreach ($header as $index => $name) {
            $normalized = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name)));
            foreach ($aliases as $key => $names) {
                if (in_array($normalized, $names, true)) {
                    $mapping[$key] ??= $index;
                }
            }
        }

        return $mapping;
    }

    private function csv(string $path): array
    {
        $text = file_get_contents($path);
        if (str_contains($text, "\0")) {
            $this->invalid('CSV должен быть текстом UTF-8 или Windows-1251.');
        }
        $encoding = mb_check_encoding($text, 'UTF-8') ? 'UTF-8' : 'Windows-1251';
        if ($encoding !== 'UTF-8') {
            $text = mb_convert_encoding($text, 'UTF-8', $encoding);
        }
        $text = preg_replace('/^\x{FEFF}/u', '', $text);
        $first = strtok($text, "\r\n") ?: '';
        $delimiter = ';';
        $best = 0;
        foreach ([';', ',', "\t"] as $candidate) {
            $size = count(str_getcsv($first, $candidate, '"', ''));
            if ($size > $best) {
                $best = $size;
                $delimiter = $candidate;
            }
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $text);
        rewind($stream);
        $rows = [];
        try {
            while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $this->append($rows, array_map(fn ($v) => trim((string) $v), $row));
            }
        } finally {
            fclose($stream);
        }

        return ['sheets' => ['CSV'], 'rows' => $rows, 'encoding' => $encoding, 'delimiter' => $delimiter];
    }

    private function xlsx(string $path, int $sheet): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('Не удалось открыть XLSX.');
        }
        try {
            $total = 0;
            if ($zip->numFiles > 2500) {
                $this->invalid('В XLSX слишком много частей.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += $stat['size'];
                if ($total > 60 * 1024 * 1024 || preg_match('/vbaproject|externalLinks/i', $stat['name'])) {
                    $this->invalid('Книга слишком большая или содержит макросы/внешние связи.');
                }
            }
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $targets = [];
            foreach ($rels->query('//*[local-name()="Relationship"]') as $node) {
                $targets[$node->getAttribute('Id')] = $node->getAttribute('Target');
            }
            $sheets = [];
            $paths = [];
            foreach ($workbook->query('//*[local-name()="sheet"]') as $node) {
                $sheets[] = $node->getAttribute('name');
                $target = $targets[$node->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')] ?? '';
                if (! preg_match('~^(?:/?xl/)?worksheets/[a-zA-Z0-9_-]+\.xml$~D', $target)) {
                    $this->invalid('Недопустимая ссылка на лист.');
                }
                $paths[] = str_starts_with(ltrim($target, '/'), 'xl/') ? ltrim($target, '/') : 'xl/'.$target;
            }
            if (! isset($paths[$sheet])) {
                $this->invalid('Выберите существующий лист.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $shared = $this->xml($zip, 'xl/sharedStrings.xml');
                foreach ($shared->query('//*[local-name()="si"]') as $node) {
                    $value = '';
                    foreach ($shared->query('.//*[local-name()="t"]', $node) as $part) {
                        $value .= $part->textContent;
                    }
                    $strings[] = $value;
                }
            }
            $xml = $this->xml($zip, $paths[$sheet]);
            $rows = [];
            $categoryRows = [];
            $highlightedStyles = [];
            if ($zip->locateName('xl/styles.xml') !== false) {
                $styles = $this->xml($zip, 'xl/styles.xml');
                $boldFonts = [];
                foreach ($styles->query('//*[local-name()="fonts"]/*[local-name()="font"]') as $index => $font) {
                    $boldFonts[$index] = $styles->query('./*[local-name()="b" and not(@val="0")]', $font)->length > 0;
                }
                foreach ($styles->query('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $index => $style) {
                    if (($boldFonts[(int) $style->getAttribute('fontId')] ?? false) || (int) $style->getAttribute('fillId') > 1) {
                        $highlightedStyles[$index] = true;
                    }
                }
            }
            foreach ($xml->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $rowNode) {
                $row = [];
                $highlighted = false;
                foreach ($xml->query('./*[local-name()="c"]', $rowNode) as $cell) {
                    preg_match('/^([A-Z]{1,3})\d+$/D', $cell->getAttribute('r'), $match);
                    $column = 0;
                    foreach (str_split($match[1] ?? 'A') as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                    }
                    if ($column > 100) {
                        $this->invalid('Поддерживается до 100 столбцов.');
                    }
                    if ($xml->query('./*[local-name()="f"]', $cell)->length) {
                        $this->invalid('Сохраните формулы как значения перед импортом.');
                    }
                    $value = $cell->getAttribute('t') === 'inlineStr'
                        ? implode('', array_map(fn ($n) => $n->textContent, iterator_to_array($xml->query('.//*[local-name()="t"]', $cell))))
                        : $xml->evaluate('string(./*[local-name()="v"])', $cell);
                    $row[$column - 1] = $cell->getAttribute('t') === 's' ? ($strings[(int) $value] ?? '') : $value;
                    if (trim($row[$column - 1]) !== '' && isset($highlightedStyles[(int) $cell->getAttribute('s')])) {
                        $highlighted = true;
                    }
                }
                if ($row) {
                    $row = array_replace(array_fill(0, max(array_keys($row)) + 1, ''), $row);
                }
                if ($highlighted && count(array_filter($row, fn ($value) => trim($value) !== '')) === 1) {
                    $categoryRows[] = count($rows);
                }
                $this->append($rows, $row);
            }

            return ['sheets' => $sheets, 'rows' => $rows, 'category_rows' => $categoryRows, 'encoding' => 'UTF-8', 'delimiter' => null];
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $path): DOMXPath
    {
        $body = $zip->getFromName($path);
        if ($body === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            $this->invalid('Некорректная структура XLSX.');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($body, LIBXML_NONET)) {
                $this->invalid('Повреждённый XLSX.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function append(array &$rows, array $row): void
    {
        if (count($rows) >= 20001 || count($row) > 100 || count(array_filter($row, fn ($v) => mb_strlen($v) > 10000))) {
            $this->invalid('Лимит: 20 000 строк, 100 столбцов, 10 000 символов в ячейке.');
        }
        $rows[] = $row;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
