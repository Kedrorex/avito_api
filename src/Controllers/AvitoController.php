<?php

namespace App\Controllers;

use App\Repositories\ItemRepository;
use App\Services\AvitoAPIClient;
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
        ];
        foreach ($stats as $stat) {
            foreach ($totals as $field => $_) {
                $totals[$field] += (int) ($stat[$field] ?? 0);
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

        if ($stats === []) {
            return;
        }

        echo "  Period:       {$stats[0]['date']} — " . $stats[array_key_last($stats)]['date'] . "\n";
        echo "  Totals: views={$totals['views']} (unique={$totals['uniq_views']}), "
            . "contacts={$totals['contacts']} (unique={$totals['uniq_contacts']}), "
            . "favorites={$totals['favorites']} (unique={$totals['uniq_favorites']})\n\n";
        echo str_pad('Date', 12) . str_pad('Views', 9) . str_pad('Contacts', 11) . str_pad('Favorites', 10) . "\n";
        echo str_repeat('-', 42) . "\n";
        foreach ($stats as $stat) {
            echo str_pad((string) $stat['date'], 12)
                . str_pad((string) $stat['views'], 9)
                . str_pad((string) $stat['contacts'], 11)
                . str_pad((string) $stat['favorites'], 10) . "\n";
        }
    }
}
