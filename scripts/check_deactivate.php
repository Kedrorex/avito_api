<?php
$json = file_get_contents('C:/Users/wings/Desktop/ProjectM/Avito/Avito_test/swager_avito/swagger.json');
$data = json_decode($json, true);

echo "=== POST/PUT endpoints for items ===\n\n";

foreach ($data['paths'] as $path => $methods) {
    foreach ($methods as $method => $info) {
        if (stripos($path, 'item') !== false && (stripos($method, 'post') !== false || stripos($method, 'put') !== false)) {
            echo "{$method} {$path}\n";
            if (isset($info['summary'])) {
                echo "  Summary: {$info['summary']}\n";
            }
            if (isset($info['operationId'])) {
                echo "  Operation: {$info['operationId']}\n";
            }
            echo "\n";
        }
    }
}
