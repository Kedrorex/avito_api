<?php
// Read the new Excel template
$filePath = 'C:/Users/wings/Desktop/ProjectM/Avito/Avito_test/fid/Для автомобилей - Двигатель - Шаблон 14-09-2026.xlsx';

if (!file_exists($filePath)) {
    die("File not found: $filePath\n");
}

$zip = new ZipArchive();
if ($zip->open($filePath) === true) {
    echo "=== Excel file structure ===\n";
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        echo "  - $filename\n";
    }
    
    // Read shared strings
    if ($zip->locateName('xl/sharedStrings.xml')) {
        echo "\n=== Shared Strings (all text values) ===\n";
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content) {
            $xml = simplexml_load_string($content);
            if ($xml) {
                $allStrings = [];
                foreach ($xml->si as $si) {
                    $text = (string)$si->t;
                    $allStrings[] = $text;
                }
                echo "Total strings: " . count($allStrings) . "\n\n";
                
                // Print first 200 strings (column headers and sample data)
                echo "--- First 200 strings ---\n";
                foreach (array_slice($allStrings, 0, 200) as $idx => $str) {
                    echo sprintf("%3d: %s\n", $idx, $str);
                }
                
                if (count($allStrings) > 200) {
                    echo "\n... (showing first 200 of " . count($allStrings) . ")\n";
                }
            }
        }
    }
    
    // Read sheet1 structure
    if ($zip->locateName('xl/worksheets/sheet1.xml')) {
        echo "\n=== Sheet1 structure ===\n";
        $content = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($content) {
            $xml = simplexml_load_string($content);
            if ($xml) {
                $totalRows = 0;
                $sampleRows = 0;
                foreach ($xml->sheetData->row as $row) {
                    $totalRows++;
                    if ($sampleRows < 3) {
                        $cells = [];
                        foreach ($row->c as $cell) {
                            $r = (string)($cell['r'] ?? '');
                            $v = (string)($cell->v ?? '');
                            $t = (string)($cell['t'] ?? '');
                            $cells[] = "$r($t)=$v";
                        }
                        echo "Row $sampleRows: " . implode(' | ', $cells) . "\n";
                        $sampleRows++;
                    }
                }
                echo "\nTotal rows in sheet: $totalRows\n";
            }
        }
    }
    
    // Read workbook.xml to get sheet names
    if ($zip->locateName('xl/workbook.xml')) {
        echo "\n=== Workbook (sheet names) ===\n";
        $content = $zip->getFromName('xl/workbook.xml');
        if ($content) {
            $xml = simplexml_load_string($content);
            if ($xml) {
                foreach ($xml->sheets->sheet as $sheet) {
                    $name = (string)$sheet['name'];
                    $sheetId = (string)$sheet['sheetId'];
                    echo "  Sheet: $name (id: $sheetId)\n";
                }
            }
        }
    }
    
    // Read _rels
    if ($zip->locateName('xl/_rels/workbook.xml.rels')) {
        echo "\n=== Relations ===\n";
        $content = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($content) {
            echo $content;
        }
    }
    
    $zip->close();
} else {
    echo "Failed to open as ZIP\n";
}
