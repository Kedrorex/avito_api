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

            $statsList = $this->apiClient->getStats(
                array_values($itemIds),
                $dateFrom,
                $dateTo
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
        $newId = $this->repository->createPhysical(
            $ad['logical_key'],
            $ad['master_data'] ? json_decode($ad['master_data'], true) : []
        );

        $this->repository->updatePhysical($newId, [
            'status' => 'active',
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
}
