<?php

namespace App\Services;

use App\Repositories\ItemRepository;

/**
 * Тонкая обёртка над FeedGeneratorService.
 *
 * Отдельный файл только из кандидатов запрещён: автозагрузка снимет всё,
 * чего нет в фиде. Эта команда пишет тот же файл, что и `feed`:
 * у порции из очереди старый Id отсутствует, на его месте новый Id.
 * Остальной каталог остаётся.
 */
class RepublishFeedService
{
    private ItemRepository $repository;
    private AvitoAPIClient $apiClient;
    private array $config;

    public function __construct(
        ItemRepository $repository,
        AvitoAPIClient $apiClient,
        array $config
    ) {
        $this->repository = $repository;
        $this->apiClient = $apiClient;
        $this->config = $config;
    }

    /**
     * Собрать фид: не больше $count кандидатов получают новый Id, остальной каталог остаётся.
     *
     * Список $candidates не используется как источник строк. Очередь читается
     * из republish_candidates_*, лимит дня — из max_daily_repub.
     *
     * @param list<array<string, mixed>> $candidates
     * @return array{
     *     feed_file: string,
     *     count: int,
     *     candidates: list<array<string, mixed>>,
     *     catalog_count: int,
     *     total_rows: int
     * }
     */
    public function generate(array $candidates, int $count): array
    {
        unset($candidates);

        $feed = new FeedGeneratorService(
            $this->repository,
            $this->apiClient,
            $this->config,
            max(0, $count)
        );
        $result = $feed->generate(true);

        return [
            'feed_file' => (string) ($result['file'] ?? ''),
            'xml_file' => (string) ($result['xml_file'] ?? $result['file'] ?? ''),
            'csv_file' => (string) ($result['csv_file'] ?? ''),
            'count' => count($result['candidate_avito_ids'] ?? []),
            'candidates' => $result['removal_ads'] ?? [],
            'catalog_count' => (int) ($result['catalog_count'] ?? 0),
            'total_rows' => (int) ($result['count'] ?? 0),
        ];
    }

    /**
     * @return list<array{physical_ad_id: int, avito_id: string, logical_key: string, added_at: string}>
     */
    public function getCandidates(): array
    {
        return $this->repository->getCandidates();
    }

    public function getCandidateCount(): int
    {
        return $this->repository->getCandidateCountForMonth(
            (int) date('Y'),
            (int) date('m')
        );
    }
}
