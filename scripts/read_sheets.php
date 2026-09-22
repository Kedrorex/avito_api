<?php
$filePath = 'C:/Users/wings/Desktop/ProjectM/Avito/Avito_test/fid/Для автомобилей - Двигатель - Шаблон 14-09-2026.xlsx';
$zip = new ZipArchive();
$zip->open($filePath);

// Read shared strings map
$content = $zip->getFromName('xl/sharedStrings.xml');
$xml = simplexml_load_string($content);
$allStrings = [];
foreach ($xml->si as $si) {
    $allStrings[] = (string)$si->t;
}

// Helper to get string value from cell
function getStringValue($cell, $allStrings) {
    $t = (string)($cell['t'] ?? '');
    $v = (string)($cell->v ?? '');
    if ($t === 's') {
        return $allStrings[(int)$v] ?? "[index:$v]";
    }
    return $v;
}

// Read sheet2 (Объявления)
$content = $zip->getFromName('xl/worksheets/sheet2.xml');
if ($content) {
    $xml = simplexml_load_string($content);
    if ($xml) {
        echo "=== Sheet2: Объявления ===\n";
        $totalRows = 0;
        foreach ($xml->sheetData->row as $row) {
            $totalRows++;
            $cells = [];
            foreach ($row->c as $cell) {
                $r = (string)($cell['r'] ?? '');
                $val = getStringValue($cell, $allStrings);
                if (trim($val) !== '') {
                    $cells[] = "$r=$val";
                }
            }
            if ($totalRows <= 5 || $totalRows <= 10) {
                echo "Row $totalRows: " . implode(' | ', $cells) . "\n";
            }
        }
        echo "Total rows: $totalRows\n\n";
    }
}

// Read sheet1 (Инструкция)
$content = $zip->getFromName('xl/worksheets/sheet1.xml');
if ($content) {
    $xml = simplexml_load_string($content);
    if ($xml) {
        echo "=== Sheet1: Инструкция ===\n";
        $totalRows = 0;
        foreach ($xml->sheetData->row as $row) {
            $totalRows++;
            $cells = [];
            foreach ($row->c as $cell) {
                $r = (string)($cell['r'] ?? '');
                $val = getStringValue($cell, $allStrings);
                if (trim($val) !== '') {
                    $cells[] = "$r=$val";
                }
            }
            if ($totalRows <= 40) {
                echo "Row $totalRows: " . implode(' | ', $cells) . "\n";
            }
        }
        echo "Total rows: $totalRows\n\n";
    }
}

$zip->close();
