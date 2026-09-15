<?php
$filePath = 'C:/Users/wings/Desktop/ProjectM/Avito/Avito_test/fid/Для автомобилей - Двигатель - Шаблон 14-09-2026.xlsx';
$zip = new ZipArchive();
$zip->open($filePath);

// Read ALL shared strings
$content = $zip->getFromName('xl/sharedStrings.xml');
$xml = simplexml_load_string($content);
$allStrings = [];
foreach ($xml->si as $si) {
    $allStrings[] = (string)$si->t;
}

echo "=== ALL Shared Strings (498 total) ===\n\n";
foreach ($allStrings as $idx => $str) {
    if (trim($str) !== '') {
        echo sprintf("%3d: [%s]\n", $idx, $str);
    }
}

$zip->close();
