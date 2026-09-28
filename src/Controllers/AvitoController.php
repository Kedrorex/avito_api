<?php

namespace App\Controllers;

use App\Repositories\ItemRepository;
use App\Services\AnalysisService;
use App\Services\AutoloadIdSyncService;
use App\Services\AvitoAPIClient;
use App\Services\FeedGeneratorService;
use App\Services\RepublishFeedService;
use App\Services\RepublisherService;
use PDO;

/**
 * Контроллер для работы с Avito API
 * Обрабатывает HTTP-запросы и делегирует логику сервисам
 */
class AvitoController
{
    private AvitoAPIClient $apiClient;
    private ItemRepository $repository;
    private RepublisherService $republisher;
    private PDO $pdo;
    private array $config;

    public function __construct(
        AvitoAPIClient $apiClient,
        ItemRepository $repository,
        RepublisherService $republisher,
        PDO $pdo,
        array $config
    ) {
        $this->apiClient = $apiClient;
        $this->repository = $repository;
        $this->republisher = $republisher;
        $this->pdo = $pdo;
        $this->config = $config;
    }

    /**
     * Запуск полного пайплайна (CLI)
     * Аналог main.py
     */
    public function run(): void
    {
        echo str_repeat('=', 60) . "\n";
        echo "  Avito Republisher -- Start\n";
        echo str_repeat('=', 60) . "\n";
        flush();

        // 1. Синхронизация с API
        echo "\n  --- Sync ---\n";
        flush();
        $items = $this->apiClient->getAllItems(['active'], 100, function(int $page, int $fetched, int $total): void {
            echo "    Fetched {$fetched} items (page {$page})...\n";
            flush();
        });
        $syncResult = $this->repository->syncFromApi($items);
        $active = $this->repository->getActive();
        
        echo "  Создано новых: " . $syncResult['created'] . "\n";
        echo "  Обновлено: " . $syncResult['updated'] . "\n";
        echo "  Удалено (снято с публикации): " . $syncResult['removed'] . "\n";
        if (!empty($syncResult['removed_ids'])) {
            echo "  Удалённые avito_id: " . implode(', ', $syncResult['removed_ids']) . "\n";
        }
        echo "  Active ads в БД: " . count($active) . "\n";

        if (empty($active)) {
            echo "  No active ads -- exit\n";
            return;
        }

        $this->syncUniqueIds(false);

        // 2. Сбор статистики
        echo "\n  --- Collect Stats ---\n";
        flush();
        $statsDays = (int) ($this->config['avito']['stats_days'] ?? 3);
        $this->republisher->collectStats($statsDays);

        // 3. Поиск кандидатов (запись в republish_candidates_*)
        echo "\n  --- Find Candidates ---\n";
        flush();
        $candidateResult = $this->republisher->collectCandidates(4);
        echo "  Найдено: {$candidateResult['found']}\n";
        echo "  Добавлено: {$candidateResult['added']}\n";
        echo "  Уже были: {$candidateResult['skipped']}\n";

        // 4. Генерация фида
        echo "\n  --- Generate Feed ---\n";
        flush();
        $feedGenerator = new FeedGeneratorService($this->repository, $this->apiClient, $this->config);
        $feedResult = $feedGenerator->generate(false);
        if ($feedResult['count'] > 0) {
            echo "  Фид: {$feedResult['file']} ({$feedResult['count']} объявлений, " . count($feedResult['headers']) . " столбцов)\n";
        }

        // 5. Итог — републикация НЕ выполняется автоматически!
        //    Для републикации используйте: php index.php republish-all <count>
        echo "\n  --- Итог ---\n";
        echo "  Кандидатов добавлено: " . $candidateResult['added'] . "\n";
        echo "  Для републикации: php index.php republish-all <count>\n";
        echo "  (например: php index.php republish-all 20)\n";

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  Done\n";
        echo str_repeat('=', 60) . "\n";
        flush();
    }

    /**
     * Запуск только генерации фида (без SYNC с Avito)
     *
     * Команда: php index.php feed-only
     *
     * 1. Получает active ads из БД
     * 2. Добирает unique_id из API Автозагрузки
     * 3. Считает дневной лимит републикации
     * 4. Берёт максимум N кандидатов (по лимиту)
     * 5. Генерирует фид
     * 6. Удаляет включённых кандидатов из БД
     */
    public function runFeedOnly(): void
    {
        echo str_repeat('=', 60) . "\n";
        echo "  Feed Generator (без SYNC)\n";
        echo str_repeat('=', 60) . "\n";
        flush();

        // 1. Получаем active ads из БД
        echo "\n  --- Active Ads из БД ---\n";
        flush();
        $active = $this->repository->getActive();
        echo "  Active ads в БД: " . count($active) . "\n";

        if (empty($active)) {
            echo "  Нет активных объявлений -- exit\n";
            return;
        }

        $this->syncUniqueIds(false);

        // 3. Считаем дневной лимит
        $maxDailyRepub = (int) ($this->config['avito']['max_daily_repub'] ?? 70);
        $dailyCount = $this->republisher->getDailyCount();
        $remaining = $maxDailyRepub - $dailyCount;
        
        echo "\n  --- Лимит републикации ---\n";
        echo "  Максимум в день: {$maxDailyRepub}\n";
        echo "  Уже сегодня: {$dailyCount}\n";
        echo "  Осталось: {$remaining}\n";

        if ($remaining <= 0) {
            echo "  [SKIP] Дневной лимит исчерпан\n";
            echo "\n" . str_repeat('=', 60) . "\n";
            echo "  Done (лимит исчерпан)\n";
            echo str_repeat('=', 60) . "\n";
            return;
        }

        // 4. Собираем кандидатов
        echo "\n  --- Find Candidates ---\n";
        flush();
        $candidateResult = $this->republisher->collectCandidates(4);
        echo "  Найдено: {$candidateResult['found']}\n";
        echo "  Добавлено: {$candidateResult['added']}\n";
        echo "  Уже были: {$candidateResult['skipped']}\n";

        // 5. Генерация фида с ограничением кандидатов
        echo "\n  --- Generate Feed ---\n";
        flush();
        $feedGenerator = new FeedGeneratorService($this->repository, $this->apiClient, $this->config, $remaining);
        $feedResult = $feedGenerator->generate(false);
        
        if ($feedResult['count'] > 0) {
            echo "  Фид: {$feedResult['file']} ({$feedResult['count']} объявлений, " . count($feedResult['headers']) . " столбцов)\n";
        }

        // 6. Удаляем включённых кандидатов из БД
        if (!empty($feedResult['candidate_avito_ids'])) {
            echo "\n  --- Удаление кандидатов из БД ---\n";
            $feedGenerator->removeCandidatesFromDb($feedResult['candidate_avito_ids']);
        }

        echo "\n  --- Итог ---\n";
        echo "  Кандидатов в фиде: " . count($feedResult['candidate_avito_ids'] ?? []) . " (лимит: {$remaining})\n";
        echo "  Файл: {$feedResult['file']}\n";

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  Done\n";
        echo str_repeat('=', 60) . "\n";
        flush();
    }

    /**
     * Получить список активных объявлений (JSON)
     */
    public function getActive(): string
    {
        $active = $this->repository->getActive();
        return json_encode([
            'status' => 'success',
            'count' => count($active),
            'data' => $active,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Синхронизировать с API (JSON)
     */
    public function sync(): string
    {
        $items = $this->apiClient->getAllItems(['active']);
        $synced = $this->repository->syncFromApi($items);
        $total = count($this->repository->getActive());

        return json_encode([
            'status' => 'success',
            'synced' => $synced,
            'total_active' => $total,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Получить статистику по объявлениям (JSON)
     */
    public function getStats(string $dateFrom, string $dateTo): string
    {
        $active = $this->repository->getActive();
        $itemIds = array_values(array_filter(
            array_map(fn($ad) => (int) ($ad['avito_id'] ?? 0), $active),
            fn($id) => $id > 0
        ));

        if (empty($itemIds)) {
            return json_encode([
                'status' => 'error',
                'message' => 'No active ads',
            ]);
        }

        $stats = $this->apiClient->getStats($itemIds, $dateFrom, $dateTo);

        return json_encode([
            'status' => 'success',
            'data' => $stats,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Переопубликовать объявление (JSON)
     */
    public function republish(int $adId): string
    {
        $ad = $this->repository->getById($adId);

        if (!$ad) {
            return json_encode([
                'status' => 'error',
                'message' => "Ad ID {$adId} not found",
            ]);
        }

        $result = $this->republisher->republish($ad);

        if ($result['success']) {
            return json_encode([
                'status' => 'success',
                'avito_id' => $result['avito_id'],
                'new_physical_id' => $result['new_id'],
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'status' => 'error',
            'message' => $result['message'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Массовая републикация кандидатов (CLI + HTTP)
     *
     * ВАЖНО: количество републикаций указывается ЯВНО пользователем.
     * Автоматическая републикация запрещена.
     *
     * @param int $maxCount Количество для републикации
     * @param bool $cli Режим CLI (true) или HTTP (false)
     */
    public function republishBatch(int $maxCount, bool $cli = true): mixed
    {
        if ($maxCount <= 0) {
            if ($cli) {
                echo "  [ERROR] Количество должно быть больше 0\n";
            } else {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Количество должно быть больше 0',
                ], JSON_UNESCAPED_UNICODE);
            }
            return null;
        }

        // Сначала собираем кандидатов в таблицу, потом читаем полные данные
        $this->republisher->collectCandidates(4);
        $candidates = $this->repository->findZeroViewCandidates(4);

        if (empty($candidates)) {
            if ($cli) {
                echo "  Нет кандидатов для републикации\n";
                return null;
            } else {
                return json_encode([
                    'status' => 'success',
                    'message' => 'Нет кандидатов для републикации',
                    'republished' => 0,
                ], JSON_UNESCAPED_UNICODE);
            }
            return null;
        }

        if ($cli) {
            $this->republishBatchCli($candidates, $maxCount);
        } else {
            $this->republishBatchHttp($candidates, $maxCount);
        }
    }

    /**
     * Массовая републикация — CLI режим с подтверждением
     */
    private function republishBatchCli(array $candidates, int $maxCount): void
    {
        echo "\n";
        echo str_repeat('=', 60) . "\n";
        echo "  МАССОВАЯ РЕПУБЛИКАЦИЯ\n";
        echo str_repeat('=', 60) . "\n";
        echo "  Кандидатов найдено: " . count($candidates) . "\n";
        echo "  Запрошено републикаций: {$maxCount}\n";
        echo "  Лимит сегодня: " . $this->republisher->getDailyCount() . "/" . ($this->config['avito']['max_daily_repub'] ?? 70) . "\n";
        echo str_repeat('=', 60) . "\n\n";

        // Показываем первые 5 кандидатов
        echo "  Первые 5 кандидатов:\n";
        foreach (array_slice($candidates, 0, 5) as $i => $ad) {
            $avitoId = $ad['avito_id'] ?? 'N/A';
            $masterData = $ad['master_data'] ? json_decode($ad['master_data'], true) : [];
            $title = $masterData['title'] ?? '';
            echo "    " . ($i + 1) . ". avito_id={$avitoId} | {$title}\n";
        }
        if (count($candidates) > 5) {
            echo "    ... и ещё " . (count($candidates) - 5) . "\n";
        }

        // Запрашиваем подтверждение
        echo "\n  Подтвердите републикацию {$maxCount} объявлений? (yes/no): ";
        $handle = fopen('php://stdin', 'r');
        $confirmation = trim(fgets($handle));
        fclose($handle);

        if (strtolower($confirmation) !== 'yes') {
            echo "  [CANCEL] Республикация отменена пользователем\n";
            return;
        }

        // Выполняем републикацию
        $result = $this->republisher->republishBatch($candidates, $maxCount);

        if ($result['republished'] > 0 && $result['failed'] === 0) {
            echo "\n  [OK] Республикация завершена успешно\n";
        } elseif ($result['republished'] > 0 && $result['failed'] > 0) {
            echo "\n  [WARN] Республикация завершена с ошибками\n";
        } else {
            echo "\n  [SKIP] Республикация не выполнена\n";
        }
    }

    /**
     * Массовая републикация — HTTP режим (без подтверждения, сразу выполняет)
     */
    private function republishBatchHttp(array $candidates, int $maxCount): string
    {
        $result = $this->republisher->republishBatch($candidates, $maxCount);

        return json_encode([
            'status' => $result['failed'] > 0 ? 'partial_success' : 'success',
            'total_candidates' => $result['total_candidates'],
            'requested' => $result['requested'],
            'republished' => $result['republished'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Подсчёт по статусам (JSON)
     */
    public function countByStatus(): string
    {
        $counts = $this->apiClient->countAllStatuses();
        $total = array_sum($counts);

        return json_encode([
            'status' => 'success',
            'data' => $counts,
            'total' => $total,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Получить детальную информацию об объявлении (JSON)
     */
    public function getItemDetail(int $itemId): string
    {
        $detail = $this->apiClient->getItemDetail($itemId);

        if (empty($detail)) {
            return json_encode([
                'status' => 'error',
                'message' => "Item {$itemId} not found",
            ]);
        }

        return json_encode([
            'status' => 'success',
            'data' => $detail,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Собрать дневную статистику объявлений всех поддержанных статусов в SQLite. */
    public function collectStatsToDatabase(int $days = 30): void
    {
        echo "Collecting statistics for advertisements of all supported statuses...\n";
        $result = $this->republisher->collectAllActiveStats($days);

        echo "\nCompleted\n";
        echo "  Period:       {$result['date_from']} — {$result['date_to']}\n";
        echo "  Loaded ads:   {$result['items']}\n";
        echo "  New DB ads:   {$result['created']}\n";
        echo "  Saved ads:    {$result['saved_items']}\n";
        echo "  Failed batch: {$result['failed_batches']}\n";
    }

    /** Вывести объявление и сохранённую статистику из SQLite без обращения к Avito API. */
    public function showStoredAd(int $identifier): void
    {
        $ad = $this->repository->getById($identifier);
        $foundBy = 'local ID';
        if ($ad === null) {
            $ad = $this->repository->getByAvitoId((string) $identifier);
            $foundBy = 'Avito ID';
        }

        if ($ad === null) {
            echo "Advertisement {$identifier} was not found in SQLite.\n";
            return;
        }

        $masterData = [];
        if (!empty($ad['master_data'])) {
            try {
                $masterData = json_decode((string) $ad['master_data'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $masterData = [];
            }
        }
        $stats = $this->repository->getStats((int) $ad['id']);
        $totals = [
            'views' => 0, 'uniq_views' => 0,
            'contacts' => 0, 'uniq_contacts' => 0,
            'favorites' => 0, 'uniq_favorites' => 0,
            'phone_shows' => 0, 'chats' => 0, 'price' => 0,
        ];
        $lastPrice = 0;
        foreach ($stats as $stat) {
            foreach ($totals as $field => $_) {
                $totals[$field] += (int) ($stat[$field] ?? 0);
            }
            // Последняя известная цена
            if (($stat['price'] ?? 0) > 0) {
                $lastPrice = (int) $stat['price'];
            }
        }

        echo "Advertisement in SQLite (found by {$foundBy})\n";
        echo "  Local ID:     {$ad['id']}\n";
        echo "  Avito ID:     " . ($ad['avito_id'] ?: '—') . "\n";
        echo "  Number:       " . ($masterData['number'] ?? '—') . "\n";
        echo "  Title:        " . ($masterData['title'] ?? '—') . "\n";
        echo "  Status:       {$ad['status']}\n";
        echo "  Published:    " . ($ad['published_at'] ?: '—') . "\n";
        echo "  Statistics:   " . count($stats) . " day(s)\n";

        if ($lastPrice > 0) {
            echo "  Current price: {$lastPrice}\n";
        }

        if ($stats === []) {
            return;
        }

        echo "  Period:       {$stats[0]['date']} — " . $stats[array_key_last($stats)]['date'] . "\n";
        echo "  Totals: views={$totals['views']} (unique={$totals['uniq_views']}), "
            . "contacts={$totals['contacts']} (unique={$totals['uniq_contacts']}), "
            . "favorites={$totals['favorites']} (unique={$totals['uniq_favorites']}), "
            . "phone_shows={$totals['phone_shows']}, chats={$totals['chats']}\n\n";
        echo str_pad('Date', 12) . str_pad('Views', 9) . str_pad('Contacts', 11) . str_pad('Favorites', 10) . str_pad('Phone', 7) . str_pad('Chats', 7) . "\n";
        echo str_repeat('-', 63) . "\n";
        foreach ($stats as $stat) {
            echo str_pad((string) $stat['date'], 12)
                . str_pad((string) $stat['views'], 9)
                . str_pad((string) $stat['contacts'], 11)
                . str_pad((string) $stat['favorites'], 10)
                . str_pad((string) ($stat['phone_shows'] ?? 0), 7)
                . str_pad((string) ($stat['chats'] ?? 0), 7) . "\n";
        }
    }

    /**
     * Собрать кандидатов для републикации (CLI + HTTP)
     */
    public function collectCandidates(int $days = 4): void
    {
        echo "Collecting zero-view candidates...\n";
        $result = $this->republisher->collectCandidates($days);

        echo "\nCompleted\n";
        echo "  Found:            {$result['found']}\n";
        echo "  Added to candidates: {$result['added']}\n";
        echo "  Skipped (already): {$result['skipped']}\n";
    }

    /**
     * Вывести список всех кандидатов (CLI)
     */
    public function showCandidates(): void
    {
        $candidates = $this->repository->getCandidates();

        echo "Republish candidates (total: " . count($candidates) . ")\n";
        echo str_repeat('-', 80) . "\n";

        if (empty($candidates)) {
            echo "  No candidates found.\n";
            return;
        }

        foreach ($candidates as $candidate) {
            $ad = $this->repository->getById((int) $candidate['physical_ad_id']);
            $title = '';
            $publishedAt = '';
            if ($ad !== null && !empty($ad['master_data'])) {
                $masterData = json_decode($ad['master_data'], true);
                $title = $masterData['title'] ?? '';
                $publishedAt = $ad['published_at'] ?? '';
            }

            echo "  ID: {$candidate['physical_ad_id']} | Avito: {$candidate['avito_id']} | "
                . "Key: {$candidate['logical_key']} | Added: {$candidate['added_at']}\n";
            if ($title !== '') {
                echo "    Title: {$title} | Published: {$publishedAt}\n";
            }
        }
    }

    /**
     * Получить список кандидатов (HTTP JSON)
     */
    public function getCandidates(): string
    {
        $candidates = $this->repository->getCandidates();
        $data = [];

        foreach ($candidates as $candidate) {
            $ad = $this->repository->getById((int) $candidate['physical_ad_id']);
            $title = '';
            $publishedAt = '';

            if ($ad !== null && !empty($ad['master_data'])) {
                $masterData = json_decode($ad['master_data'], true);
                $title = $masterData['title'] ?? '';
                $publishedAt = $ad['published_at'] ?? '';
            }

            $data[] = [
                'physical_ad_id' => (int) $candidate['physical_ad_id'],
                'avito_id' => (string) $candidate['avito_id'],
                'logical_key' => (string) $candidate['logical_key'],
                'added_at' => (string) $candidate['added_at'],
                'title' => $title,
                'published_at' => $publishedAt,
            ];
        }

        return json_encode([
            'status' => 'success',
            'count' => count($data),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Удалить кандидата (HTTP)
     */
    public function removeCandidate(int $physicalAdId): string
    {
        try {
            $this->republisher->removeCandidate($physicalAdId);
            return json_encode([
                'status' => 'success',
                'message' => "Candidate {$physicalAdId} removed",
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Запустить анализ всех объявлений (CLI)
     */
    public function analyze(): void
    {
        echo "Running analysis on all active ads...\n";

        $analysis = new AnalysisService($this->repository, $this->config);
        $candidates = $analysis->findAllCandidates();

        echo "\nAnalysis complete\n";
        echo "  Total active ads: " . count($this->repository->getActive()) . "\n";
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
    }

    /**
     * Показать отчёт по анализу (CLI + HTTP)
     */
    public function analyzeReport(): string
    {
        $analysis = new AnalysisService($this->repository, $this->config);
        $candidates = $analysis->findAllCandidates();
        $activeCount = count($this->repository->getActive());

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

        return json_encode([
            'status' => 'success',
            'total_active' => $activeCount,
            'total_candidates' => count($candidates),
            'rule_counts' => $ruleCounts,
            'candidates' => array_map(function ($item) {
                return [
                    'avito_id' => $item['ad']['avito_id'] ?? '',
                    'title' => $this->extractTitle($item['ad']),
                    'total_views' => $item['analysis']['total_views'] ?? 0,
                    'total_contacts' => $item['analysis']['total_contacts'] ?? 0,
                    'matched_rules' => $item['analysis']['matched_rules'] ?? [],
                ];
            }, $candidates),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Сгенерировать TSV фид для Avito AutoLoad (HTTP POST)
     *
     * @param bool $priorityMode Приоритетный режим (кандидаты первыми)
     */
    public function generateFeed(bool $priorityMode = true): string
    {
        try {
            $feedGenerator = new FeedGeneratorService($this->repository, $this->apiClient, $this->config);
            $result = $feedGenerator->generate($priorityMode);

            return json_encode([
                'status' => 'success',
                'file' => $result['file'] ?? '',
                'count' => $result['count'] ?? 0,
                'headers' => $result['headers'] ?? [],
                'priority_mode' => $priorityMode,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Извлечь title из master_data JSON
     */
    private function extractTitle(array $ad): string
    {
        if (empty($ad['master_data'])) {
            return '';
        }
        $masterData = json_decode($ad['master_data'], true);
        return $masterData['title'] ?? '';
    }

    /**
     * Получить информацию о последней генерации фида (HTTP GET)
     */
    public function getFeedInfo(): string
    {
        try {
            $feedGenerator = new FeedGeneratorService($this->repository, $this->apiClient, $this->config);
            $info = $feedGenerator->getLastGeneration();

            return json_encode([
                'status' => 'success',
                'data' => empty($info) ? null : $info,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Сгенерировать фид для переопубликования (CLI + HTTP)
     *
     * HTTP: POST /republish-feeds с телом {"count": 10}
     * CLI:  php index.php republish-feeds <count>
     *
     * Формат: ОДИН фид с mixed operations (remove + update)
     *
     * @param int|null $count Количество кандидатов для включения (до 70), null для HTTP
     * @param bool $cli Режим CLI (true) или HTTP (false)
     */
    public function generateRepublishFeeds(?int $count = null, bool $cli = true): mixed
    {
        // Для HTTP режима читаем count из тела запроса
        if (!$cli && $count === null) {
            $input = json_decode(file_get_contents('php://input'), true);
            $count = (int) ($input['count'] ?? 0);
        }

        if ($count <= 0) {
            if ($cli) {
                echo "  [ERROR] Количество должно быть больше 0\n";
            } else {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Количество должно быть больше 0',
                ], JSON_UNESCAPED_UNICODE);
            }
            return null;
        }

        // Получаем кандидатов
        $feedService = new RepublishFeedService($this->repository, $this->apiClient, $this->config);
        $candidates = $feedService->getCandidates();

        if (empty($candidates)) {
            if ($cli) {
                echo "  Нет кандидатов для переопубликования\n";
                echo "  Сначала выполните: php index.php collect-candidates\n";
                return null;
            } else {
                return json_encode([
                    'status' => 'success',
                    'message' => 'Нет кандидатов для переопубликования',
                    'feed_file' => '',
                    'count' => 0,
                ], JSON_UNESCAPED_UNICODE);
            }
        }

        // Проверка лимита
        $maxCount = min($count, 70);
        if ($maxCount > count($candidates)) {
            $maxCount = count($candidates);
        }

        if ($cli) {
            echo "\n";
            echo str_repeat('=', 60) . "\n";
            echo "  ГЕНЕРАЦИЯ ФИДА ДЛЯ РЕПУБЛИКАЦИИ\n";
            echo "  (ОДИН фид с mixed operations: remove + update)\n";
            echo str_repeat('=', 60) . "\n";
            echo "  Всего кандидатов:  " . count($candidates) . "\n";
            echo "  Включим в фид:     {$maxCount}\n";
            echo str_repeat('=', 60) . "\n\n";

            // Показываем первых 5 кандидатов
            echo "  Первые 5 кандидатов:\n";
            foreach (array_slice($candidates, 0, 5) as $i => $c) {
                $ad = $this->repository->getById((int) $c['physical_ad_id']);
                $title = '';
                if ($ad !== null && !empty($ad['master_data'])) {
                    $masterData = json_decode($ad['master_data'], true);
                    $title = $masterData['title'] ?? '';
                }
                $uniqueId = $ad['unique_id'] ?? 'N/A';
                echo "    " . ($i + 1) . ". avito_id=" . ($c['avito_id'] ?? 'N/A')
                    . " | unique_id={$uniqueId} | ID: {$c['physical_ad_id']} | {$title}\n";
            }
            if (count($candidates) > 5) {
                echo "    ... и ещё " . (count($candidates) - 5) . "\n";
            }

            // Выполняем генерацию
            $result = $feedService->generate($candidates, $maxCount);

            if (!empty($result['feed_file'])) {
                echo "\n  [OK] Фид сгенерирован успешно\n";
                echo "\n  Следующие шаги:\n";
                echo "    1. Проверить файл в директории fid/\n";
                echo "    2. Загрузить фид на облако Avito\n";
                echo "    3. Отправить команду Avito на обновление\n";
            } else {
                echo "\n  [ERROR] Не удалось сгенерировать фид\n";
            }

            return null;
        }

        // HTTP режим
        $result = $feedService->generate($candidates, $maxCount);

        return json_encode([
            'status' => 'success',
            'feed_file' => $result['feed_file'] ?? '',
            'count' => $result['count'] ?? 0,
            'candidates' => array_map(function ($ad) {
                $masterData = json_decode($ad['master_data'] ?? '', true);
                return [
                    'physical_ad_id' => (int) ($ad['id'] ?? 0),
                    'avito_id' => (string) ($ad['avito_id'] ?? ''),
                    'unique_id' => (string) ($ad['unique_id'] ?? ''),
                    'title' => $masterData['title'] ?? '',
                ];
            }, $result['candidates'] ?? []),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Запросить unique_id в API Автозагрузки и записать в БД.
     *
     * @param bool $all true — все active и low_perf, false — только пустые и равные номеру Авито
     */
    public function syncUniqueIds(bool $all = false): void
    {
        echo "\n  --- Sync AutoLoad IDs ---\n";
        flush();
        $sync = new AutoloadIdSyncService($this->apiClient, $this->repository, $this->config['avito']);
        $sync->sync($all);
    }

    /**
     * Ручной импорт CSV из кабинета. В run() больше не вызывается.
     */
    public function importFeedFromFile(?string $path = null): void
    {
        $feedPath = $path ?? (string) ($this->config['feed']['autoload_source'] ?? '');
        echo "\n  --- Import AutoLoad Feed ---\n";
        if ($feedPath === '' || !file_exists($feedPath)) {
            echo "  Файл не найден: {$feedPath}\n";
            return;
        }
        echo "  Файл: {$feedPath}\n";
        $imported = $this->importAutoloadFeed($feedPath);
        echo "  Импортировано: {$imported} объявлений\n";
    }

    /**
     * Импорт данных из AutoLoad CSV файла Avito
     *
     * Заполняет колонки БД: unique_id, phone, description, images, brand, oem_number, title, location, price
     *
     * @return int Количество обновлённых записей
     */
    private function importAutoloadFeed(string $filePath): int
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            return 0;
        }

        // Убираем BOM
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
        }

        $lines = explode("\n", $raw);
        $lines = array_map('rtrim', $lines);
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));

        if (count($lines) < 8) {
            return 0;
        }

        // Строка 2 — заголовки
        $headers = str_getcsv($lines[1], ';');

        // Строки 8+ — данные
        $dataLines = array_slice($lines, 7);

        // Маппинг
        $headerMap = [];
        foreach ($headers as $i => $h) {
            $headerMap[trim($h)] = $i;
        }

        $stmt = $this->pdo->prepare("
            UPDATE physical_ads SET 
                unique_id = :unique_id,
                phone = :phone,
                description = :description,
                images = :images,
                brand = :brand,
                oem_number = :oem_number,
                title = :title,
                location = :location,
                price = :price
            WHERE avito_id = :avito_id
        ");

        $updated = 0;
        foreach ($dataLines as $line) {
            $row = str_getcsv($line, ';');

            $avitoId = trim($row[$headerMap['Номер объявления на Авито']] ?? '');
            if ($avitoId === '') continue;

            // Ищем в БД
            $existing = $this->pdo->prepare("SELECT id FROM physical_ads WHERE avito_id = :avito_id");
            $existing->execute([':avito_id' => $avitoId]);
            $dbRow = $existing->fetch(PDO::FETCH_ASSOC);

            if ($dbRow === false) continue;

            $uniqueId = trim($row[$headerMap['Уникальный идентификатор объявления']] ?? '');
            $phone = trim($row[$headerMap['Номер телефона']] ?? '');
            $description = strip_tags(trim($row[$headerMap['Описание объявления']] ?? ''));
            $imagesRaw = trim($row[$headerMap['Ссылки на фото']] ?? '');
            $images = $imagesRaw !== '' ? json_encode(explode('|', $imagesRaw), JSON_UNESCAPED_UNICODE) : '';
            $brand = trim($row[$headerMap['Производитель']] ?? '');
            $oem = trim($row[$headerMap['Номер детали OEM']] ?? '');
            $title = trim($row[$headerMap['Название объявления']] ?? '');
            $address = trim($row[$headerMap['Адрес']] ?? '');
            $price = (int) ($row[$headerMap['Цена']] ?? 0);

            $stmt->execute([
                ':unique_id' => $uniqueId,
                ':phone' => $phone,
                ':description' => $description,
                ':images' => $images,
                ':brand' => $brand,
                ':oem_number' => $oem,
                ':title' => $title,
                ':location' => $address,
                ':price' => $price,
                ':avito_id' => $avitoId,
            ]);

            $updated++;
        }

        return $updated;
    }
}
