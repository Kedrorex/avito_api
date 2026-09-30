<?php

declare(strict_types=1);

namespace App\Cli;

use App\Accounts\AccountRuntime;
use App\Services\AnalysisService;
use App\Services\FeedGeneratorService;
use App\Services\YandexDiskAuth;
use App\Services\YandexDiskFeedUploader;
use App\Support\EnvFile;

/**
 * Разбор CLI-команд одного уже собранного контура: корневого или аккаунта.
 */
final class CommandDispatcher
{
    /**
     * @param list<string> $args Аргументы после имени команды.
     */
    public function dispatch(string $command, array $args, AccountRuntime $runtime): int
    {
        $controller = $runtime->controller();
        $repository = $runtime->repository();
        $apiClient = $runtime->apiClient();
        $config = $runtime->config();

        switch ($command) {
            case 'run':
                $controller->run();
                if (!$controller->cloudPublished()) {
                    return 1;
                }
                break;
            case 'run-r':
                $controller->runLocal();
                break;
            case 'run-test':
                $forcedRemoval = isset($args[0]) ? (int) $args[0] : 0;
                if (isset($args[0]) && ($forcedRemoval < 1 || $forcedRemoval > 70)) {
                    echo "Usage: php index.php run-test [count: 1..70]\n";
                    echo "  Без числа — замена из очереди в файле, очередь и новое поколение в базе не пишутся.\n";
                    echo "  С числом — столько первых объявлений каталога получить новый Id в файле, без очереди и без нового поколения в базе.\n";

                    return 1;
                }
                $controller->runTest($forcedRemoval);
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
                $adId = $args[0] ?? null;
                if (!$adId) {
                    echo "Usage: php index.php republish <ad_id>\n";

                    return 1;
                }
                echo $controller->republish((int) $adId) . "\n";
                break;
            case 'republish-all':
                $maxCount = isset($args[0]) ? (int) $args[0] : 0;
                if ($maxCount <= 0) {
                    echo "Usage: php index.php republish-all <count: 1..70>\n";
                    echo "  ВАЖНО: републикация происходит ТОЛЬКО при явном указании количества.\n";
                    echo "  Автоматическая републикация запрещена.\n";

                    return 1;
                }
                $maxDailyRepub = (int) ($config['avito']['max_daily_repub'] ?? 70);
                if ($maxCount > $maxDailyRepub) {
                    echo "  [ERROR] Запрошено {$maxCount}, но дневной лимит: {$maxDailyRepub}\n";

                    return 1;
                }
                $controller->republishBatch($maxCount, true);
                break;
            case 'stats':
                $dateFrom = $args[0] ?? date('Y-m-d', strtotime('-30 days'));
                $dateTo = $args[1] ?? date('Y-m-d', strtotime('-1 day'));
                echo $controller->getStats($dateFrom, $dateTo) . "\n";
                break;
            case 'collect-stats':
                $days = isset($args[0]) ? (int) $args[0] : 30;
                if ($days < 1 || $days > 270) {
                    echo "Usage: php index.php collect-stats [days: 1..270]\n";

                    return 1;
                }
                $controller->collectStatsToDatabase($days);
                break;
            case 'ad':
                $identifier = $args[0] ?? null;
                if (!is_string($identifier) || !preg_match('/^[1-9][0-9]*$/', $identifier)) {
                    echo "Usage: php index.php ad <local_or_avito_id>\n";

                    return 1;
                }
                $controller->showStoredAd((int) $identifier);
                break;
            case 'item':
                $itemId = $args[0] ?? null;
                if (!$itemId) {
                    echo "Usage: php index.php item <item_id>\n";

                    return 1;
                }
                echo $controller->getItemDetail((int) $itemId) . "\n";
                break;
            case 'collect-candidates':
                $configCandidateDays = (int) ($config['avito']['candidate_days'] ?? 4);
                $days = isset($args[0]) ? (int) $args[0] : $configCandidateDays;
                $controller->collectCandidates($days);
                break;
            case 'show-candidates':
                $controller->showCandidates();
                break;
            case 'feed':
                $priorityMode = true;
                $keepQueue = false;
                foreach ($args as $arg) {
                    if ($arg === '--flat') {
                        $priorityMode = false;
                    } elseif ($arg === '--priority') {
                        $priorityMode = true;
                    } elseif ($arg === '--keep-queue') {
                        $keepQueue = true;
                    }
                }
                $feedGenerator = new FeedGeneratorService($repository, $apiClient, $config);
                $result = $feedGenerator->generate($priorityMode, $keepQueue);
                if ($result['count'] > 0) {
                    echo '  Режим: ' . ($priorityMode ? 'приоритетный' : 'обычный') . "\n";
                }
                if (!$keepQueue && ($result['file'] ?? '') !== '' && !$controller->publishFeedFile((string) $result['file'])) {
                    return 1;
                }
                break;
            case 'upload-feed':
                $feedDir = (string) ($config['feed']['output_dir'] ?? '');
                $file = YandexDiskFeedUploader::latestProductionFeed($feedDir);
                if ($file === null) {
                    echo "  Нет боевого фида avito_feed_YYYY-MM-DD.xml в {$feedDir}\n";

                    return 1;
                }
                echo "  Файл: {$file}\n";
                if (!$controller->publishFeedFile($file)) {
                    return 1;
                }
                break;
            case 'disk-url':
                $envFile = (string) ($config['feed']['env_file'] ?? '');
                $current = trim((string) ($config['feed']['yandex_disk_public_url'] ?? ''));
                $next = trim((string) ($args[0] ?? ''));
                if ($next === '') {
                    echo $current === '' ? "  Ссылка не задана\n" : "  Ссылка: {$current}\n";
                    echo "  Файл: {$envFile}\n";
                    echo "  Сменить: php index.php disk-url https://disk.yandex.ru/d/...\n";
                    break;
                }
                if ($envFile === '') {
                    echo "  [ERROR] Не найден env-файл кабинета\n";

                    return 1;
                }
                try {
                    $next = YandexDiskFeedUploader::assertPublicUrl($next);
                    EnvFile::set($envFile, 'YANDEX_DISK_PUBLIC_URL', $next);
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    echo "  [ERROR] {$e->getMessage()}\n";

                    return 1;
                }
                echo "  Ссылка кабинета: {$next}\n";
                echo "  Записано в {$envFile}\n";
                break;
            case 'disk-auth':
                $envFile = (string) ($config['feed']['env_file'] ?? '');
                $clientId = trim((string) ($config['feed']['yandex_disk_client_id'] ?? ''));
                $clientSecret = trim((string) ($config['feed']['yandex_disk_client_secret'] ?? ''));
                $code = trim((string) ($args[0] ?? ''));
                if ($code === '') {
                    try {
                        $url = YandexDiskAuth::authorizeUrl($clientId);
                    } catch (\InvalidArgumentException $e) {
                        echo "  [ERROR] {$e->getMessage()}\n";

                        return 1;
                    }
                    echo "  Откройте ссылку, разрешите доступ к Диску и скопируйте код со страницы:\n";
                    echo "  {$url}\n";
                    echo "  Затем: php index.php disk-auth КОД\n";
                    echo "  В приложении Яндекса Redirect URI: " . YandexDiskAuth::REDIRECT_URI . "\n";
                    echo "  Доступ: чтение и запись всего Диска.\n";
                    break;
                }
                if ($envFile === '') {
                    echo "  [ERROR] Не найден env-файл кабинета\n";

                    return 1;
                }
                try {
                    $tokens = YandexDiskAuth::exchange($clientId, $clientSecret, $code);
                    EnvFile::set($envFile, 'YANDEX_DISK_TOKEN', $tokens['access_token']);
                    if ($tokens['refresh_token'] !== '') {
                        EnvFile::set($envFile, 'YANDEX_DISK_REFRESH_TOKEN', $tokens['refresh_token']);
                    }
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    echo "  [ERROR] {$e->getMessage()}\n";

                    return 1;
                }
                echo "  Токен Диска записан в {$envFile}\n";
                echo "  Дальше: php index.php upload-feed\n";
                break;
            case 'feed-keep':
                $keep = isset($args[0]) ? (int) $args[0] : 10;
                if ($keep < 1 || $keep > 500) {
                    echo "Usage: php index.php feed-keep [count]\n";
                    echo "  По умолчанию 10. В файле только эти объявления, остальные Авито снимет.\n";

                    return 1;
                }
                $feedGenerator = new FeedGeneratorService($repository, $apiClient, $config);
                $feedGenerator->generateKeep($keep);
                break;
            case 'feed-inactive':
            case 'feed-deleted':
                $limit = 0;
                $includeActive = true;
                foreach ($args as $arg) {
                    if ($arg === '--only-inactive') {
                        $includeActive = false;
                    } elseif (ctype_digit($arg)) {
                        $limit = (int) $arg;
                    }
                }
                $controller->generateInactiveFeed($limit, $includeActive);
                break;
            case 'feed-info':
                $feedGenerator = new FeedGeneratorService($repository, $apiClient, $config);
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
                $count = isset($args[0]) ? (int) $args[0] : 0;
                if ($count <= 0) {
                    echo "Usage: php index.php republish-feeds <count: 1..70>\n";
                    echo "  Тот же фид, что и feed: у порции из очереди старый Id пропадает,\n";
                    echo "  вместо него пишется новый Id. Остальной каталог остаётся.\n";
                    echo "  count ограничивает только эту порцию.\n";

                    return 1;
                }
                $controller->generateRepublishFeeds($count, true);
                break;
            case 'analyze':
                $analysis = new AnalysisService($repository, $config);
                $candidates = $analysis->findAllCandidates();

                echo "\nAnalysis complete\n";
                echo '  Total active ads: ' . count($repository->getActive()) . "\n";
                echo '  Candidates found: ' . count($candidates) . "\n";

                foreach ($candidates as $i => $item) {
                    $ad = $item['ad'];
                    $analysisResult = $item['analysis'];
                    $avitoId = $ad['avito_id'] ?? 'N/A';
                    $title = '';
                    if (!empty($ad['master_data'])) {
                        $masterData = json_decode($ad['master_data'], true);
                        $title = $masterData['title'] ?? '';
                    }

                    echo '  ' . ($i + 1) . ". avito_id={$avitoId} "
                        . "views={$analysisResult['total_views']} "
                        . "contacts={$analysisResult['total_contacts']} "
                        . 'rules=' . implode(',', $analysisResult['matched_rules']) . "\n";
                    if ($title !== '') {
                        echo "     Title: {$title}\n";
                    }
                }
                break;
            case 'analyze-report':
                $analysis = new AnalysisService($repository, $config);
                $candidates = $analysis->findAllCandidates();
                $activeCount = count($repository->getActive());

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
                echo '  Total candidates:   ' . count($candidates) . "\n";
                echo "  Rule breakdown:\n";
                foreach ($ruleCounts as $rule => $count) {
                    echo "    {$rule}: {$count}\n";
                }

                echo "\n  Candidates:\n";
                foreach ($candidates as $i => $item) {
                    $ad = $item['ad'];
                    $analysisResult = $item['analysis'];
                    echo '    ' . ($i + 1) . '. avito_id=' . ($ad['avito_id'] ?? 'N/A')
                        . " views={$analysisResult['total_views']}"
                        . " contacts={$analysisResult['total_contacts']}"
                        . ' rules=' . implode(',', $analysisResult['matched_rules']) . "\n";
                }
                break;
            case 'sync-unique-ids':
                $all = in_array('--all', $args, true);
                $controller->syncUniqueIds($all);
                break;
            case 'import-feed':
                $force = in_array('--force', $args, true);
                $dryRun = in_array('--dry-run', $args, true);
                $feedPath = $args[0] ?? null;
                if (is_string($feedPath) && !str_starts_with($feedPath, '--')) {
                    $controller->importFeedFromFile($feedPath, $dryRun);
                } else {
                    $controller->importFeedFromApi($force);
                }
                break;
            case 'migrate-unique-id':
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
                echo "Available: run, run-r, run-test, schedule, feed-only, sync, active, status-counts, republish, republish-all, stats, item, collect-stats, ad, collect-candidates, show-candidates, feed, upload-feed, disk-url, disk-auth, feed-keep, feed-inactive, feed-info, republish-feeds, analyze, analyze-report, sync-unique-ids, import-feed, migrate-unique-id, account, accounts\n";

                return 1;
        }

        return 0;
    }
}
