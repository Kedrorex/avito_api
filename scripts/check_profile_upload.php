<?php
$json = file_get_contents('C:/Users/wings/Desktop/ProjectM/Avito/Avito_test/swager_avito/swagger (9).json');
$data = json_decode($json, true);

// Подробно по ключевым эндпоинтам
$keyPaths = [
    '/autoload/v2/profile',
    '/autoload/v1/upload',
    '/autoload/v2/upload',
];

foreach ($keyPaths as $keyPath) {
    if (!isset($data['paths'][$keyPath])) continue;
    
    echo "=== {$keyPath} ===\n\n";
    foreach ($data['paths'][$keyPath] as $method => $info) {
        echo "Method: {$method}\n";
        if (isset($info['summary'])) echo "Summary: {$info['summary']}\n";
        if (isset($info['operationId'])) echo "Operation: {$info['operationId']}\n";
        
        // Request body
        if (isset($info['requestBody'])) {
            echo "Request Body:\n";
            $content = $info['requestBody']['content']['application/json']['schema'] ?? [];
            echo json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
        
        // Response
        if (isset($info['responses']['200'])) {
            echo "Response 200:\n";
            $content = $info['responses']['200']['content']['application/json']['schema'] ?? [];
            echo json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
        echo "\n---\n\n";
    }
}
