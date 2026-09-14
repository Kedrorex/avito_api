<?php
require __DIR__ . '/vendor/autoload.php';

$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

echo "getenv: " . (getenv('AVITO_CLIENT_ID') ?: 'NOT SET') . "\n";
echo "server: " . (isset($_SERVER['AVITO_CLIENT_ID']) ? $_SERVER['AVITO_CLIENT_ID'] : 'NOT SET') . "\n";
echo "env: " . (isset($_ENV['AVITO_CLIENT_ID']) ? $_ENV['AVITO_CLIENT_ID'] : 'NOT SET') . "\n";
