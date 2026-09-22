<?php
$json = file_get_contents('C:/Users/wings/Desktop/ProjectM/Avito/Avito_test/swager_avito/swagger (9).json');
$data = json_decode($json, true);

echo "=== All paths (autoload/feed related) ===\n\n";

foreach ($data['paths'] as $path => $methods) {
    if (stripos($path, 'autoload') !== false || stripos($path, 'feed') !== false || stripos($path, 'export') !== false || stripos($path, 'catalog') !== false) {
        echo "Path: $path\n";
        foreach ($methods as $method => $info) {
            echo "  $method\n";
            if (isset($info['summary'])) echo "    Summary: {$info['summary']}\n";
            if (isset($info['operationId'])) echo "    Operation: {$info['operationId']}\n";
            if (isset($info['description']) && strlen($info['description']) > 0) {
                $desc = preg_replace('/\s+/', ' ', $info['description']);
                echo "    Desc: " . substr($desc, 0, 300) . "\n";
            }
        }
        echo "\n";
    }
}
