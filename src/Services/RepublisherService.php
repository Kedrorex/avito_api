<?php

namespace App\Services;

use App\Repositories\ItemRepository;

/**
 * Сервис переопубликования объявлений
 *
 * Аналог Python republisher.py
 *
 * Логика:
 * 1. collectStats — собирает статистику (views/contacts) по активным
 * 2. findCandidates — находит "слабые" (0 контактов)
 * 3. republish — деактивирует старое, создаёт новое поколение
 */
class RepublisherService
{
    private AvitoAPIClient $apiClient;
    private ItemRepository $repository;
    private array $config;

    public function __construct(
        AvitoAPIClient $apiClient,
        ItemRepository $repository,
        array $config
    ) {
        $this->apiClient = $apiClient;
        $this->repository = $repository;
        $this->config = $config;
    }

    /**
     * Собрать статистику за N дней для всех активных объявлений
     */
    public function collectStats(int $days = 3): void
    {
        $active = $this->repository->getActive();
        if (empty($active)) {
            echo "  Нет активных объявлений для сбора статистики\n";
            return;
        }

        $dateTo = date('Y-m-d', strtotime('-1 day'));
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        // Делим на пачки по 200
        for ($i = 0; $i < count($active); $i += 200) {
            $batch = array_slice($active, $i, 200);
            $itemIds = array_filter(
                array_map(fn($ad) => $ad['avito_id'] ?? null, $batch),
                fn($id) => $id !== null
            );

            if (empty($itemIds)) {
                continue;
            }

            $statsList = $this->apiClient->getStatsWithDelay(
                array_values($itemIds),
                $dateFrom,
                $dateTo,
                'item'
            );

            // Группируем статистику по itemId
            $statsByItem = [];
            foreach ($statsList as $statItem) {
                $itemId = (string) ($statItem['itemId'] ?? '');
                if (!isset($statsByItem[$itemId])) {
                    $statsByItem[$itemId] = [];
                }
                $statsByItem[$itemId] = array_merge(
                    $statsByItem[$itemId],
                    $statItem['stats'] ?? []
                );
            }

            // Сохраняем статистику в БД
            foreach ($batch as $ad) {
                $avitoId = (string) ($ad['avito_id'] ?? '');
                if (!isset($statsByItem[$avitoId])) {
                    continue;
                }

                $this->repository->saveStats((int) $ad['id'], $statsByItem[$avitoId]);
            }

            echo "  Собрано статистики для " . count($itemIds) . " объявлений: "
                . count($statsList) . " записей\n";
        }
    }

    /**
     * Получить из Avito объявления всех поддержанных статусов и сохранить дневную статистику.
     *
     * @return array{items:int, created:int, batches:int, saved_items:int, failed_batches:int, date_from:string, date_to:string}
     */
    public function collectAllActiveStats(int $days = 30): array
    {
        if ($days < 1 || $days > 270) {
            throw new \InvalidArgumentException('Days must be between 1 and 270.');
        }

        // API возвращает только active, если не передать все статусы явно.
        // Список взят из enum GET /core/v1/items в Swagger.
        $statuses = $this->config['item_statuses'] ?? ['active', 'removed', 'old', 'blocked', 'rejected'];
        echo "  Loading advertisement list...\n";
        $items = $this->apiClient->getAllItems(
            $statuses,
            100,
            static function (int $page, int $loaded, int $total): void {
                $totalLabel = $total > 0 ? (string) $total : '?';
                echo "  List page {$page}: {$loaded}/{$totalLabel} ads loaded\n";
            }
        );
        $created = $this->repository->syncFromApi($items);
        $dateTo = date('Y-m-d', strtotime('-1 day'));
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));
        $delaySeconds = (int) ($this->config['stats_request_delay_seconds'] ?? 65);
        // Пакеты по 1000 (максимум API для stats)
        $batches = array_chunk($items, 1000);
        $savedItems = 0;
        $failedBatches = 0;

        foreach ($batches as $batchNumber => $batch) {
            $itemIds = array_values(array_filter(array_map(
                static fn(array $item): int => (int) ($item['id'] ?? 0),
                $batch
            )));
            if ($itemIds === []) {
                continue;
            }

            $displayBatch = $batchNumber + 1;
            echo "  Batch {$displayBatch}/" . count($batches) . ': ' . count($itemIds) . " ads...\n";

            try {
                $statsList = $this->requestStatsWithRetry($itemIds, $dateFrom, $dateTo, $displayBatch);
                $statsByItemId = [];
                foreach ($statsList as $statItem) {
                    $itemId = (string) ($statItem['itemId'] ?? '');
                    if ($itemId !== '') {
                        $statsByItemId[$itemId] = $statItem['stats'] ?? [];
                    }
                }

                $this->repository->beginTransaction();
                try {
                    foreach ($itemIds as $itemId) {
                        $ad = $this->repository->getByAvitoId((string) $itemId);
                        if ($ad === null || !isset($statsByItemId[(string) $itemId])) {
                            continue;
                        }
                        $this->repository->saveStats((int) $ad['id'], $statsByItemId[(string) $itemId]);
                        $savedItems++;
                    }
                    $this->repository->commit();
                } catch (\Throwable $e) {
                    $this->repository->rollBack();
                    throw $e;
                }
            } catch (\Throwable $e) {
                $failedBatches++;
                fwrite(STDERR, "  [ERROR] Batch {$displayBatch}: {$e->getMessage()}\n");
            }

            // Пауза 65 сек между запросами статистики (API limit: 1 req/min)
            // Один запрос обслуживает до 200 объявлений.
            if ($batchNumber < count($batches) - 1 && $delaySeconds > 0) {
                echo "  Waiting {$delaySeconds}s before next statistics request...\n";
                sleep($delaySeconds);
            }
        }

        return [
            'items' => count($items),
            'created' => $created,
            'batches' => count($batches),
            'saved_items' => $savedItems,
            'failed_batches' => $failedBatches,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /** @param list<int> $itemIds @return list<array<string, mixed>> */
    private function requestStatsWithRetry(array $itemIds, string $dateFrom, string $dateTo, int $batchNumber): array
    {
        $maxRetries = max(0, (int) ($this->config['max_retries'] ?? 3));
        $retryDelay = max(65, (int) ($this->config['retry_delay_base'] ?? 65));

        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->apiClient->getStatsWithDelay(
                    $itemIds,
                    $dateFrom,
                    $dateTo,
                    'item'
                );
            } catch (\Throwable $e) {
                if ($attempt >= $maxRetries) {
                    throw $e;
                }

                $nextAttempt = $attempt + 2;
                fwrite(
                    STDERR,
                    "  [WARN] Batch {$batchNumber} failed: {$e->getMessage()}. "
                    . "Retry {$nextAttempt}/" . ($maxRetries + 1) . " in {$retryDelay}s...\n"
                );
                sleep($retryDelay);
            }
        }
    }

    /**
     * Найти объявления-кандидаты для републикации
     *
     * Критерии:
     * - Возраст >= min_age_days
     * - contacts <= threshold за последние N дней
     * - Сортировка: меньше просмотров → выше приоритет
     */
    public function findCandidates(
        int $days = 3,
        int $threshold = 0
    ): array {
        $activeAds = $this->repository->getActive();
        $minAgeDays = (int) ($this->config['min_age_days'] ?? 3);
        $candidates = [];

        foreach ($activeAds as $ad) {
            if (!$ad['published_at']) {
                continue;
            }

            $publishedAt = new \DateTime($ad['published_at']);
            $age = (new \DateTime())->diff($publishedAt)->days;

            if ($age < $minAgeDays) {
                continue;
            }

            // Получить статистику из БД
            $stats = $this->repository->getStats((int) $ad['id']);
            $hasStats = count($stats) > 0;

            $contacts = 0;
            $views = 0;

            if ($hasStats) {
                // Берём последние N дней
                $recentStats = array_slice($stats, -$days);
                foreach ($recentStats as $s) {
                    $contacts += (int) ($s['contacts'] ?? 0);
                    $views += (int) ($s['views'] ?? $s['uniq_views'] ?? 0);
                }

                if ($contacts > $threshold) {
                    continue; // не кандидат — есть контакты
                }
            } else {
                $views = 0;
            }

            $candidates[] = [
                'ad' => $ad,
                'age_days' => $age,
                'contacts' => $contacts,
                'views' => $views,
                'has_stats' => $hasStats,
            ];
        }

        // Сортируем: меньше просмотров → выше приоритет
        usort($candidates, fn($a, $b) => $a['views'] <=> $b['views']);

        // Возвращаем только массивы объявлений
        return array_map(fn($c) => $c['ad'], $candidates);
    }

    /**
     * Переопубликовать одно объявление
     *
     * @return int|null ID нового поколения или null при ошибке
     */
    public function republish(array $ad): ?int
    {
        $maxDailyRepub = (int) ($this->config['max_daily_repub'] ?? 70);
        $today = date('Y-m-d');
        $dailyCount = $this->repository->getDailyRepubCount($today);

        // Проверка дневного лимита
        if ($dailyCount >= $maxDailyRepub) {
            echo "  [SKIP] Дневной лимит ({$maxDailyRepub}) исчерпан\n";
            return null;
        }

        $avitoId = (string) ($ad['avito_id'] ?? '');

        // 1. Деактивируем старое на Avito
        if ($avitoId !== '') {
            $success = $this->apiClient->deactivateItem((int) $avitoId);
            if (!$success) {
                echo "  [ERROR] Не удалось деактивировать {$avitoId}\n";
                $this->repository->updatePhysical((int) $ad['id'], ['status' => 'error']);
                return null;
            }
        }

        // Обновляем статус
        $this->repository->updatePhysical((int) $ad['id'], [
            'status' => 'deactivated',
            'deactivated_at' => date('Y-m-d H:i:s'),
        ]);

        // 2. Создаём новое поколение
        $masterData = $ad['master_data'] ? json_decode($ad['master_data'], true) : [];
        $newId = $this->repository->createPhysical(
            $ad['logical_key'],
            $masterData,
            'active'
        );

        $this->repository->updatePhysical($newId, [
            'published_at' => date('Y-m-d H:i:s'),
            'old_avito_id' => $avitoId ?: null,
        ]);

        $newDailyCount = $dailyCount + 1;
        echo "  [REPUB] {$avitoId} -> новое поколение #{$newId} "
            . "(всего сегодня: {$newDailyCount}/{$maxDailyRepub})\n";

        return $newId;
    }

    /**
     * Получить дневной счётчик републикаций
     */
    public function getDailyCount(): int
    {
        return $this->repository->getDailyRepubCount(date('Y-m-d'));
    }

    /**
     * Собрать объявления-кандидаты с 0 просмотрами
     *
     * @return array{found: int, added: int, skipped: int}
     */
    public function collectCandidates(int $days = 4): array
    {
        $candidateDays = (int) ($this->config['candidate_days'] ?? $days);
        $candidates = $this->repository->findZeroViewCandidates($candidateDays);

        $found = count($candidates);
        $added = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $physicalAdId = (int) $candidate['id'];
            $avitoId = (string) ($candidate['avito_id'] ?? '');
            $logicalKey = (string) ($candidate['logical_key'] ?? '');

            if ($avitoId === '') {
                continue;
            }

            $inserted = $this->repository->addCandidate($physicalAdId, $avitoId, $logicalKey);

            if ($inserted) {
                // Обновляем статус на low_perf
                $this->repository->updatePhysical($physicalAdId, ['status' => 'low_perf']);
                $added++;
            } else {
                $skipped++;
            }
        }

        echo "  Found: {$found}\n";
        echo "  Added to candidates: {$added}\n";
        echo "  Skipped (already): {$skipped}\n";

        return [
            'found' => $found,
            'added' => $added,
            'skipped' => $skipped,
        ];
    }

    /**
     * Удалить кандидата (вызывается при републикации)
     */
    public function removeCandidate(int $physicalAdId): void
    {
        $this->repository->removeCandidate($physicalAdId);
        $this->repository->updatePhysical($physicalAdId, ['status' => 'active']);
    }
}
