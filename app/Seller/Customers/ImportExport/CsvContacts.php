<?php

namespace App\Seller\Customers\ImportExport;

use Illuminate\Validation\ValidationException;

final class CsvContacts
{
    public function read(string $path): array
    {
        $text = file_get_contents($path);
        if (! is_string($text) || str_contains($text, "\0")) {
            throw ValidationException::withMessages(['file' => 'Нужен текстовый CSV.']);
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1251');
        }
        $text = preg_replace('/^\x{FEFF}/u', '', $text);
        $line = strtok($text, "\r\n") ?: '';
        $delimiter = count(str_getcsv($line, ';', '"', '')) >= count(str_getcsv($line, ',', '"', '')) ? ';' : ',';
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $text);
        rewind($stream);
        $rows = [];
        try {
            while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                if (count($rows) >= 1001 || count($row) > 50) {
                    throw ValidationException::withMessages(['file' => 'Не более 1000 строк и 50 колонок.']);
                }
                $row = array_map(fn ($v) => trim((string) $v), $row);
                if (array_filter($row, fn ($v) => $v !== '')) {
                    $rows[] = $row;
                }
            }
        } finally {
            fclose($stream);
        }
        if (count($rows) < 2 || count($rows[0]) === 0) {
            throw ValidationException::withMessages(['file' => 'Нужны заголовок и хотя бы одна строка.']);
        }

        return ['headers' => array_shift($rows), 'rows' => $rows];
    }
}
