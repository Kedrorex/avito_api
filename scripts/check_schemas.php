<?php
$json = file_get_contents('C:/Users/wings/Desktop/ProjectM/Avito/Avito_test/swager_avito/swagger (9).json');
$data = json_decode($json, true);

// Посмотрю схемы
$schemas = ['UpsertProfileInV2', 'FeedsData', 'ExportSchedule', 'UploadResponse'];

foreach ($schemas as $schemaName) {
    if (isset($data['components']['schemas'][$schemaName])) {
        echo "=== Schema: {$schemaName} ===\n";
        echo json_encode($data['components']['schemas'][$schemaName], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    }
}
