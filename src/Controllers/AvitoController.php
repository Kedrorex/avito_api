<?php

namespace App\Controllers;

use App\Repositories\ItemRepository;
use App\Services\AnalysisService;
use App\Services\AvitoAPIClient;
use App\Services\FeedGeneratorService;
use App\Services\RepublisherService;

/**
 * Контроллер для работы с Avito API
 * Обрабатывает HTTP-запросы и делегирует логику сервисам
 */
class AvitoController
{
    private AvitoAPIClient $apiClient;
    private ItemRepository $repository;
    private RepublisherService $republisher;
    private array $config;

    public function __construct(
        AvitoAPIClient $apiClient,
        ItemRepository $repository,
        RepublisherService $republisher,
        array $config
    ) {
        $this->apiClient = $apiClient;
        $this->repository = $repository;
        $this->republisher = $republisher;
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

        // 1. Синхронизация с API
        echo "\n  --- Sync ---\n";
        $items = $this->apiClient->getAllItems(['active']);
        $synced = $this->repository->syncFromApi($items);
        $active = $this->repository->getActive();
        echo "  Active ads in DB: " . count($active) . "\n";

        if (empty($active)) {
            echo "  No active ads -- exit\n";
            return;
        }

        // 2. Сбор статистики
        echo "\n  --- Collect Stats ---\n";
        $this->republisher->collectStats(3);

        // 3. Поиск кандидатов
        echo "\n  --- Find Candidates ---\n";
        $candidates = $this->republisher->findCandidates(3, 0);
        echo "  Candidates found: " . count($candidates) . "\n";

        foreach (array_slice($candidates, 0, 5) as $i => $ad) {
            $stats = $this->repository->getStats((int) $ad['id']);
            $recent = array_slice($stats, -3);
            $views = array_sum(array_map(fn($s) => (int) ($s['views'] ?? $s['uniq_views'] ?? 0), $recent));
            $contacts = array_sum(array_map(fn($s) => (int) ($s['contacts'] ?? $s['uniq_contacts'] ?? 0), $recent));
            echo "    " . ($i + 1) . ". avito_id=" . ($ad['avito_id'] ?? 'N/A')
                . "  views={$views}  contacts={$contacts}\n";
        }

        // 4. Республикация
        echo "\n  --- Republish ---\n";
        $dailyCount = $this->republisher->getDailyCount();
        $maxRepub = min(70 - $dailyCount, count($candidates));
        echo "  Today limit: " . (70 - $dailyCount) . ", candidates: " . count($candidates) . "\n";

        $republished = 0;
        foreach (array_slice($candidates, 0, $maxRepub) as $candidate) {
            $newId = $this->republisher->republish($candidate);
            if ($newId) {
                $republished++;
            }
        }

        echo "\n  Republished: {$republished}\n";
        echo "  Used today: " . ($dailyCount + $republished) . "/70\n";

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  Done\n";
        echo str_repeat('=', 60) . "\n";
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
        $itemIds = array_map(fn($ad) => (int) $ad['avito_id'], array_filter($active));

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
        $ad = $this->repository->getByLogicalKey('');
        $found = null;
        foreach ($this->repository->getAll() as $a) {
            if ((int) $a['id'] === $adId) {
                $found = $a;
                break;
            }
        }

        if (!$found) {
            return json_encode([
                'status' => 'error',
                'message' => "Ad ID {$adId} not found",
            ]);
        }

        $newId = $this->republisher->republish($found);

        if ($newId) {
            return json_encode([
                'status' => 'success',
                'new_physical_id' => $newId,
            ]);
        }

        return json_encode([
            'status' => 'error',
            'message' => 'Republish failed',
        ]);
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
            $feedGenerator = new FeedGeneratorService($this->repository, $this->config);
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
            $feedGenerator = new FeedGeneratorService($this->repository, $this->config);
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
}
