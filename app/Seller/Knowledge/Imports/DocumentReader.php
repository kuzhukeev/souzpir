<?php

namespace App\Seller\Knowledge\Imports;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;
use Throwable;

final class DocumentReader
{
    /** @return array{text: string, pages: ?int} */
    public function read(string $path, string $type): array
    {
        try {
            if ($type === 'pdf') {
                $pdf = (new Parser)->parseFile($path);
                $pages = $pdf->getPages();

                return ['text' => implode("\n", array_map(fn ($page) => $page->getText(), $pages)), 'pages' => count($pages)];
            }
            $document = IOFactory::load($path, 'Word2007');
            $lines = [];
            foreach ($document->getSections() as $section) {
                $this->collect($section, $lines);
            }

            return ['text' => implode("\n", $lines), 'pages' => null];
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'Не удалось прочитать документ. Проверьте формат и содержимое файла.']);
        }
    }

    private function collect(object $element, array &$lines): void
    {
        if ($element instanceof Table) {
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    $this->collect($cell, $lines);
                }
            }
        } elseif (method_exists($element, 'getText') && is_string($text = $element->getText())) {
            $lines[] = $text;
        } elseif (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $this->collect($child, $lines);
            }
        }
    }
}
