<?php

namespace App\Shell\Data;

use RuntimeException;
use ZipArchive;

final class CustomerSpreadsheet
{
    public static function build(string $jsonPath, string $csvPath, string $xlsxPath): void
    {
        $input = fopen($jsonPath, 'rb');
        $csv = fopen($csvPath, 'xb');
        if ($input === false || $csv === false) {
            throw new RuntimeException('Unable to create customer export.');
        }
        $columns = ['id', 'name', 'phone', 'email', 'city', 'created_at'];
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $number = 0;
        $write = static function (array $row) use ($csv, &$sheet, &$number): void {
            fputcsv($csv, $row, ',', '"', '');
            $number++;
            $sheet .= '<row r="'.$number.'">';
            foreach ($row as $index => $value) {
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value) ?? '';
                $sheet .= '<c r="'.chr(65 + $index).$number.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $sheet .= '</row>';
        };
        try {
            fwrite($csv, "\xEF\xBB\xBF");
            $write(['ID', 'Имя', 'Телефон', 'Почта', 'Город', 'Создан']);
            while (($line = fgets($input)) !== false) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (($record['type'] ?? null) !== 'contact') {
                    continue;
                }
                $write(array_map(fn ($column) => (string) ($record['data'][$column] ?? ''), $columns));
            }
        } finally {
            fclose($input);
            fclose($csv);
        }
        $sheet .= '</sheetData></worksheet>';
        $zip = new ZipArchive;
        if ($zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Unable to create XLSX.');
        }
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Клиенты" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        foreach ($files as $name => $content) {
            if (! $zip->addFromString($name, $content)) {
                $zip->close();
                throw new RuntimeException('Unable to write XLSX.');
            }
        }
        if (! $zip->close()) {
            throw new RuntimeException('Unable to finish XLSX.');
        }
    }
}
