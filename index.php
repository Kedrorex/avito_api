<?php
/**
 * Точка входа Avito Republisher
 *
 * Запуск:
 *   CLI:  php index.php run
 *   HTTP: php -S localhost:8080 index.php
 */

declare(strict_types=1);

use Slim\Factory\AppFactory;

// Загрузка зависимостей
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/env_helper.php';

// Загрузка .env
$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Загрузка конфигурации
$config = require __DIR__ . '/config/avito.php';

// Создание Slim приложения
$app = AppFactory::create();

// Middleware
$app->addRoutingMiddleware();

// Регистрация маршрутов
$routes = require __DIR__ . '/routes/api.php';
$routes($app);

// Error handler
$app->addErrorMiddleware(true, true, true);

// ==================== CLI или HTTP ====================

$cli = php_sapi_name() === 'cli';

if ($cli) {
    // CLI режим: проверяем первый аргумент
    $argv = $_SERVER['argv'] ?? [];
    $command = $argv[1] ?? 'run';

    // Создаём зависимости
    $pdo = new PDO(
        $config['database']['dsn'],
        null,
        null,
        $config['database']['options']
    );

    $apiClient = new \App\Services\AvitoAPIClient($config['avito']);
    $repository = new \App\Repositories\ItemRepository($pdo);
    $republisher = new \App\Services\RepublisherService($apiClient, $repository, $config['avito']);
    $controller = new \App\Controllers\AvitoController(
        $apiClient,
        $repository,
        $republisher,
        $config['avito']
    );

    // Dispatch команд
    switch ($command) {
        case 'run':
            $controller->run();
            break;
        case 'sync':
            echo $controller->sync() . "\n";
            break;
        case 'active':
            echo $controller->getActive() . "\n";
            break;
        case 'status-counts':
            echo $controller->countByStatus() . "\n";
            break;
        case 'republish':
            $adId = $argv[2] ?? null;
            if (!$adId) {
                echo "Usage: php index.php republish <ad_id>\n";
                exit(1);
            }
            echo $controller->republish((int) $adId) . "\n";
            break;
        case 'stats':
            $dateFrom = $argv[2] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $argv[3] ?? date('Y-m-d', strtotime('-1 day'));
            echo $controller->getStats($dateFrom, $dateTo) . "\n";
            break;
        case 'collect-stats':
            $days = isset($argv[2]) ? (int) $argv[2] : 30;
            if ($days < 1 || $days > 270) {
                echo "Usage: php index.php collect-stats [days: 1..270]\n";
                exit(1);
            }
            $controller->collectStatsToDatabase($days);
            break;
        case 'ad':
            $identifier = $argv[2] ?? null;
            if (!is_string($identifier) || !preg_match('/^[1-9][0-9]*$/', $identifier)) {
                echo "Usage: php index.php ad <local_or_avito_id>\n";
                exit(1);
            }
            $controller->showStoredAd((int) $identifier);
            break;
        case 'item':
            $itemId = $argv[2] ?? null;
            if (!$itemId) {
                echo "Usage: php index.php item <item_id>\n";
                exit(1);
            }
            echo $controller->getItemDetail((int) $itemId) . "\n";
            break;
        default:
            echo "Unknown command: {$command}\n";
            echo "Available: run, sync, active, status-counts, republish, stats, item, collect-stats, ad\n";
            exit(1);
    }
} else {
    // HTTP режим
    $app->run();
}
