<?php

namespace App\Services;

use App\Repositories\ItemRepository;

/**
 * Подтягивает unique_id из Автозагрузки по номеру объявления на Авито.
 *
 * GET /autoload/v2/items/ad_ids
 */
class AutoloadIdSyncService
{
    private AutoloadIdProvider $apiClient;
    private ItemRepository $repository;
    private array $config;

    public function __construct(AutoloadIdProvider $apiClient, ItemRepository $repository, array $config)
    {
        $this->apiClient = $apiClient;
        $this->repository = $repository;
        $this->config = $config;
    }

    /**
     * @return array{
     *     requested: int,
     *     updated: int,
     *     missing: int,
     *     error: string
     * }
     */
    public function sync(bool $all = false): array
    {
        $batchSize = max(1, min(100, (int) ($this->config['autoload_id_batch_size'] ?? 100)));
        $delay = max(0, (int) ($this->config['autoload_request_delay_seconds'] ?? 1));
        $ids = $this->repository->listAvitoIdsForUniqueSync($all);

        $result = [
            'requested' => count($ids),
            'updated' => 0,
            'missing' => 0,
            'error' => '',
        ];

        if ($ids === []) {
            echo "  Нет объявлений без unique_id фида\n";
            return $result;
        }

        $batches = array_chunk($ids, $batchSize);
        echo "  Объявлений к запросу: " . count($ids) . "\n";
        echo "  Пакетов: " . count($batches) . " по {$batchSize}\n";
        flush();

        foreach ($batches as $index => $chunk) {
            $number = $index + 1;
            echo "  Пакет {$number}/" . count($batches) . " (" . count($chunk) . ")\n";
            flush();

            try {
                $items = $this->apiClient->getAdIdsByAvitoIds($chunk);
            } catch (\Throwable $e) {
                $result['error'] = $this->explainError($e);
                echo "  [ERROR] {$result['error']}\n";
                return $result;
            }

            $byAvitoId = [];
            foreach ($items as $item) {
                $byAvitoId[$item['avito_id']] = $item['ad_id'];
            }

            $pairs = [];
            foreach ($chunk as $avitoId) {
                $adId = $byAvitoId[$avitoId] ?? null;
                if ($adId === null || $adId === '') {
                    $result['missing']++;
                    continue;
                }
                $pairs[$avitoId] = $adId;
            }

            $result['updated'] += $this->repository->applyAutoloadIds($pairs);

            if ($index < count($batches) - 1 && $delay > 0) {
                sleep($delay);
            }
        }

        echo "  Обновлено unique_id: {$result['updated']}\n";
        echo "  Без Id в Автозагрузке: {$result['missing']}\n";

        return $result;
    }

    /**
     * Номер Авито => Id автозагрузки. Нужен, чтобы не создавать вторую строку на то же объявление.
     *
     * @param list<string> $avitoIds
     * @return array<string, string>
     */
    public function mapAdIds(array $avitoIds): array
    {
        $ids = [];
        foreach ($avitoIds as $avitoId) {
            $avitoId = trim((string) $avitoId);
            if ($avitoId !== '' && ctype_digit($avitoId)) {
                $ids[$avitoId] = $avitoId;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return [];
        }

        $batchSize = max(1, min(100, (int) ($this->config['autoload_id_batch_size'] ?? 100)));
        $delay = max(0, (int) ($this->config['autoload_request_delay_seconds'] ?? 1));
        $map = [];
        $batches = array_chunk($ids, $batchSize);

        foreach ($batches as $index => $chunk) {
            try {
                $items = $this->apiClient->getAdIdsByAvitoIds($chunk);
            } catch (\Throwable $e) {
                echo '  [WARN] не удалось сверить Id автозагрузки: ' . $this->explainError($e) . "\n";
                return $map;
            }
            foreach ($items as $item) {
                $avitoId = trim((string) ($item['avito_id'] ?? ''));
                $adId = trim((string) ($item['ad_id'] ?? ''));
                if ($avitoId !== '' && $adId !== '') {
                    $map[$avitoId] = $adId;
                }
            }
            if ($index < count($batches) - 1 && $delay > 0) {
                sleep($delay);
            }
        }

        return $map;
    }

    private function explainError(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, '403')) {
            return 'Нет доступа к Автозагрузке (HTTP 403). Включите право autoload у приложения в кабинете разработчика.';
        }

        return $message;
    }
}
