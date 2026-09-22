<?php
require __DIR__ . '/vendor/autoload.php';

$file = __DIR__ . '/fid/Для автомобилей - Двигатель - Шаблон 14-09-2026.xlsx';
echo "File exists: " . (file_exists($file) ? 'yes' : 'no') . PHP_EOL;
echo "File size: " . filesize($file) . PHP_EOL;

// Try to read with PhpSpreadsheet
try {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    $worksheet = $spreadsheet->getActiveSheet();
    echo "Sheet name: " . $worksheet->getTitle() . PHP_EOL;
    echo "Max row: " . $worksheet->getHighestRow() . PHP_EOL;
    echo "Max col: " . $worksheet->getHighestColumn() . PHP_EOL;
    
    echo PHP_EOL . "=== All cells ===" . PHP_EOL;
    foreach ($worksheet->getRowIterator() as $row) {
        $rowNum = $row->getRowIndex();
        $cellValues = [];
        foreach ($row->getCellIterator() as $cell) {
            $cellValues[$cell->getColumn()] = $cell->getValue();
        }
        echo "Row {$rowNum}: " . json_encode($cellValues, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
} catch (\Exception $e) {
    echo "Error loading with PhpSpreadsheet: " . $e->getMessage() . PHP_EOL;
}

// Also try with openpyxl via subprocess
$proc = proc_open(
    'python -c "import openpyxl, sys; wb = openpyxl.load_workbook(sys.argv[1]); ws = wb.active; print(ws.max_row); print(ws.max_column); [print([c.value for c in list(r)]) for r in ws.iter_rows()]" "' . $file . '"',
    [['pipe','r'], ['pipe','o'], ['pipe','e']],
    $pipes
);
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
proc_close($proc);
echo PHP_EOL . "=== Python output ===" . PHP_EOL;
echo $output;
if ($error) echo "STDERR: " . $error;
