<?php

namespace App\Support;

use XMLReader;

/**
 * Минимальный потоковый читатель XLSX без внешних зависимостей.
 *
 * Нужен для файла выгрузки Автозагрузки: лист с объявлениями занимает
 * десятки мегабайт в распакованном виде, поэтому строки читаются потоком.
 */
class XlsxReader
{
    private const NS_SPREADSHEET = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_OFFICE_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_PACKAGE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private string $path;
    /** @var list<string>|null */
    private ?array $sharedStrings = null;

    public function __construct(string $path)
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Файл не найден: {$path}");
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Нужно расширение PHP ext-zip для чтения XLSX.');
        }
        $this->path = $path;
    }

    /**
     * Листы книги: имя => путь к XML внутри архива.
     *
     * @return array<string, array{target: string, hidden: bool}>
     */
    public function sheets(): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->path) !== true) {
            throw new \RuntimeException("Не удалось открыть XLSX: {$this->path}");
        }

        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->close();

        if ($workbook === false || $rels === false) {
            throw new \RuntimeException('XLSX без workbook.xml — файл повреждён.');
        }

        $relMap = [];
        $relsXml = new \SimpleXMLElement($rels);
        foreach ($relsXml->children(self::NS_PACKAGE_REL) as $rel) {
            $attrs = $rel->attributes();
            $relMap[(string) $attrs['Id']] = (string) $attrs['Target'];
        }

        $sheets = [];
        $workbookXml = new \SimpleXMLElement($workbook);
        $workbookRoot = $workbookXml->children(self::NS_SPREADSHEET);
        if (!isset($workbookRoot->sheets)) {
            return [];
        }

        foreach ($workbookRoot->sheets->sheet as $sheet) {
            $sheetAttrs = $sheet->attributes();
            $name = (string) ($sheetAttrs['name'] ?? '');
            $relAttrs = $sheet->attributes(self::NS_OFFICE_REL);
            $rid = (string) ($relAttrs['id'] ?? '');
            $target = $relMap[$rid] ?? '';
            if ($target === '') {
                continue;
            }
            $sheets[$name] = [
                'target' => 'xl/' . ltrim($target, '/'),
                'hidden' => ((string) ($sheetAttrs['state'] ?? '')) === 'hidden',
            ];
        }

        return $sheets;
    }

    /**
     * Строки листа. Значения в порядке колонок, пропуски заполнены пустыми строками.
     *
     * @return \Generator<int, list<string>>
     */
    public function rows(string $sheetTarget, int $limit = 0): \Generator
    {
        $strings = $this->sharedStrings();

        $reader = new XMLReader();
        if (!$reader->open('zip://' . $this->path . '#' . $sheetTarget)) {
            throw new \RuntimeException("Не удалось прочитать лист: {$sheetTarget}");
        }

        $rowNumber = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $xml = $reader->readOuterXml();
                if ($xml === '') {
                    continue;
                }

                $rowNumber++;
                yield $rowNumber => $this->parseRow($xml, $strings);

                if ($limit > 0 && $rowNumber >= $limit) {
                    return;
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param list<string> $strings
     * @return list<string>
     */
    private function parseRow(string $xml, array $strings): array
    {
        $node = @simplexml_load_string($xml);
        if ($node === false) {
            return [];
        }

        $cells = $node->children(self::NS_SPREADSHEET);
        if (!isset($cells->c)) {
            $cells = $node;
        }

        $values = [];
        foreach ($cells->c as $cell) {
            $cellAttrs = $cell->attributes();
            $index = $this->columnIndex((string) ($cellAttrs['r'] ?? ''));
            $type = (string) ($cellAttrs['t'] ?? '');

            $cellData = $cell->children(self::NS_SPREADSHEET);
            if (!isset($cellData->v) && isset($cell->v)) {
                $cellData = $cell;
            }

            if ($type === 's') {
                $value = $strings[(int) (string) $cellData->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $inline = $cellData->is ?? $cell->is;
                $value = (string) ($inline->t ?? '');
            } else {
                $value = (string) ($cellData->v ?? '');
            }

            $values[$index] = trim($value);
        }

        if ($values === []) {
            return [];
        }

        $max = max(array_keys($values));
        $row = [];
        for ($i = 0; $i <= $max; $i++) {
            $row[$i] = $values[$i] ?? '';
        }

        return $row;
    }

    /** Ссылка вида "AB12" -> индекс колонки с нуля. */
    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?? '';
        if ($letters === '') {
            return 0;
        }

        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    /** @return list<string> */
    private function sharedStrings(): array
    {
        if ($this->sharedStrings !== null) {
            return $this->sharedStrings;
        }

        $strings = [];
        $reader = new XMLReader();
        if (!$reader->open('zip://' . $this->path . '#xl/sharedStrings.xml')) {
            $this->sharedStrings = [];
            return $this->sharedStrings;
        }

        $buffer = null;
        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    $buffer = '';
                    if ($reader->isEmptyElement) {
                        $strings[] = '';
                        $buffer = null;
                    }
                } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $buffer !== null) {
                    $buffer .= $reader->readString();
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si' && $buffer !== null) {
                    $strings[] = $buffer;
                    $buffer = null;
                }
            }
        } finally {
            $reader->close();
        }

        $this->sharedStrings = $strings;

        return $this->sharedStrings;
    }
}
