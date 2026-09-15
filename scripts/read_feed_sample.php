<?php
// Read Excel feed sample using PHP
$filePath = 'C:/Users/wings/Desktop/ProjectM/Avito/Avito_test/fid/424709423_2026-09-11T12_18_05Z.xlsx';

if (!file_exists($filePath)) {
    die("File not found: $filePath\n");
}

// Try to read as ZIP (Excel files are ZIP archives)
$zip = new ZipArchive();
if ($zip->open($filePath) === true) {
    echo "=== Excel file structure ===\n";
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        echo "  - $filename\n";
    }
    
    // Read shared strings (contains text values)
    if ($zip->locateName('xl/sharedStrings.xml')) {
        echo "\n=== Shared Strings (column names) ===\n";
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content) {
            $xml = simplexml_load_string($content);
            if ($xml) {
                foreach ($xml->si as $si) {
                    $text = (string)$si->t;
                    echo "  - $text\n";
                }
            }
        }
    }
    
    // Read sheet1
    if ($zip->locateName('xl/worksheets/sheet1.xml')) {
        echo "\n=== Sheet1 (first 5 rows) ===\n";
        $content = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($content) {
            $xml = simplexml_load_string($content);
            if ($xml) {
                $rowNum = 0;
                foreach ($xml->sheetData->row as $row) {
                    if ($rowNum >= 5) break;
                    $cells = [];
                    foreach ($row->c as $cell) {
                        $val = (string)($cell->v ?? '');
                        $cells[] = $val;
                    }
                    echo "  Row $rowNum: " . implode(' | ', $cells) . "\n";
                    $rowNum++;
                }
            }
        }
    }
    
    $zip->close();
} else {
    echo "Failed to open as ZIP\n";
}
