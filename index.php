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

    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

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
        $pdo,
        $config
    );

    // Dispatch команд
    switch ($command) {
        case 'run':
            $controller->run();
            break;
        case 'feed-only':
            $controller->runFeedOnly();
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
        case 'republish-all':
            $maxCount = isset($argv[2]) ? (int) $argv[2] : 0;
            if ($maxCount <= 0) {
                echo "Usage: php index.php republish-all <count: 1..70>\n";
                echo "  ВАЖНО: републикация происходит ТОЛЬКО при явном указании количества.\n";
                echo "  Автоматическая републикация запрещена.\n";
                exit(1);
            }
            $maxDailyRepub = (int) ($config['avito']['max_daily_repub'] ?? 70);
            if ($maxCount > $maxDailyRepub) {
                echo "  [ERROR] Запрошено {$maxCount}, но дневной лимит: {$maxDailyRepub}\n";
                exit(1);
            }
            $controller->republishBatch($maxCount, true);
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
        case 'collect-candidates':
            $configCandidateDays = (int) ($config['avito']['candidate_days'] ?? 4);
            $days = isset($argv[2]) ? (int) $argv[2] : $configCandidateDays;
            $controller->collectCandidates($days);
            break;
        case 'show-candidates':
            $controller->showCandidates();
            break;
        case 'feed':
            // Парсим флаги --priority и --flat
            $priorityMode = true; // по умолчанию — приоритетный
            for ($i = 2; $i < count($argv); $i++) {
                if ($argv[$i] === '--flat') {
                    $priorityMode = false;
                } elseif ($argv[$i] === '--priority') {
                    $priorityMode = true;
                }
            }
            $feedGenerator = new \App\Services\FeedGeneratorService($repository, $apiClient, $config);
            $result = $feedGenerator->generate($priorityMode);
            if ($result['count'] > 0) {
                echo "\n  Заголовки (первые 5):\n";
                foreach (array_slice($result['headers'], 0, 5) as $i => $h) {
                    echo "    " . ($i + 1) . ". {$h}\n";
                }
                echo "    ... всего: " . count($result['headers']) . "\n";
                echo "  Режим: " . ($priorityMode ? 'приоритетный' : 'обычный') . "\n";
            }
            break;
        case 'feed-info':
            $feedGenerator = new \App\Services\FeedGeneratorService($repository, $apiClient, $config);
            $info = $feedGenerator->getLastGeneration();
            if (empty($info)) {
                echo "  Нет сгенерированных файлов\n";
            } else {
                echo "  Файл:   {$info['last_file']}\n";
                echo "  Дата:   {$info['last_date']}\n";
                echo "  Объявл: {$info['last_count']}\n";
            }
            break;
        case 'republish-feeds':
            $count = isset($argv[2]) ? (int) $argv[2] : 0;
            if ($count <= 0) {
                echo "Usage: php index.php republish-feeds <count: 1..70>\n";
                echo "  Генерирует ОДИН TSV фид для переопубликования:\n";
                echo "  Формат: mixed operations (remove + update в одном файле)\n";
                echo "  Avito AutoLoad обрабатывает фид последовательно:\n";
                echo "    1. Сначала все remove (снять с публикации)\n";
                echo "    2. Затем все update (вновь включить с полными данными)\n";
                exit(1);
            }
            $controller->generateRepublishFeeds($count, true);
            break;
        case 'analyze':
            $analysis = new \App\Services\AnalysisService($repository, $config);
            $candidates = $analysis->findAllCandidates();

            echo "\nAnalysis complete\n";
            echo "  Total active ads: " . count($repository->getActive()) . "\n";
            echo "  Candidates found: " . count($candidates) . "\n";

            foreach ($candidates as $i => $item) {
                $ad = $item['ad'];
                $analysisResult = $item['analysis'];
                $avitoId = $ad['avito_id'] ?? 'N/A';
                $title = '';
                if (!empty($ad['master_data'])) {
                    $masterData = json_decode($ad['master_data'], true);
                    $title = $masterData['title'] ?? '';
                }

                echo "  " . ($i + 1) . ". avito_id={$avitoId} "
                    . "views={$analysisResult['total_views']} "
                    . "contacts={$analysisResult['total_contacts']} "
                    . "rules=" . implode(',', $analysisResult['matched_rules']) . "\n";
                if ($title !== '') {
                    echo "     Title: {$title}\n";
                }
            }
            break;
        case 'analyze-report':
            $analysis = new \App\Services\AnalysisService($repository, $config);
            $candidates = $analysis->findAllCandidates();
            $activeCount = count($repository->getActive());

            // Группируем по правилам
            $ruleCounts = [];
            foreach ($candidates as $item) {
                $rules = $item['analysis']['matched_rules'] ?? [];
                foreach ($rules as $rule) {
                    if (!isset($ruleCounts[$rule])) {
                        $ruleCounts[$rule] = 0;
                    }
                    $ruleCounts[$rule]++;
                }
            }

            echo "\nAnalysis Report\n";
            echo str_repeat('-', 60) . "\n";
            echo "  Total active ads:   {$activeCount}\n";
            echo "  Total candidates:   " . count($candidates) . "\n";
            echo "  Rule breakdown:\n";
            foreach ($ruleCounts as $rule => $count) {
                echo "    {$rule}: {$count}\n";
            }

            echo "\n  Candidates:\n";
            foreach ($candidates as $i => $item) {
                $ad = $item['ad'];
                $analysisResult = $item['analysis'];
                echo "    " . ($i + 1) . ". avito_id=" . ($ad['avito_id'] ?? 'N/A')
                    . " views={$analysisResult['total_views']}"
                    . " contacts={$analysisResult['total_contacts']}"
                    . " rules=" . implode(',', $analysisResult['matched_rules']) . "\n";
            }
            break;
        case 'sync-unique-ids':
            $all = in_array('--all', $argv, true);
            $controller->syncUniqueIds($all);
            break;
        case 'import-feed':
            $feedPath = $argv[2] ?? null;
            $controller->importFeedFromFile(is_string($feedPath) ? $feedPath : null);
            break;
        case 'migrate-unique-id':
            // Миграция: заполняет unique_id из master_data для существующих объявлений
            echo "\n  Миграция unique_id для существующих объявлений...\n";
            $activeAds = $repository->getActive();
            $updated = 0;
            $skipped = 0;
            foreach ($activeAds as $ad) {
                if (!empty($ad['unique_id'])) {
                    $skipped++;
                    continue;
                }
                $masterData = $ad['master_data'] ? json_decode($ad['master_data'], true) : [];
                $uniqueId = $masterData['unique_id'] ?? '';
                if ($uniqueId !== '') {
                    $repository->updatePhysical((int) $ad['id'], ['unique_id' => $uniqueId]);
                    $updated++;
                } else {
                    $skipped++;
                }
            }
            echo "  Обновлено: {$updated}\n";
            echo "  Пропущено (уже есть unique_id или нет в master_data): {$skipped}\n";
            break;
        default:
            echo "Unknown command: {$command}\n";
            echo "Available: run, feed-only, sync, active, status-counts, republish, republish-all, stats, item, collect-stats, ad, collect-candidates, show-candidates, feed, feed-info, republish-feeds, analyze, analyze-report, sync-unique-ids, import-feed, migrate-unique-id\n";
            exit(1);
    }
} else {
    // HTTP режим
    $app->run();
}
