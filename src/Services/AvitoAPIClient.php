<?php

declare(strict_types=1);

namespace App\Services;

use Avito\OAuth2\Client\Provider\Avito;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client for Avito API authenticated by avito/oauth2-avito.
 */
final class AvitoAPIClient
{
    private Avito $provider;
    private ?AccessTokenInterface $accessToken = null;
    private string $apiBaseUrl;
    private string $userId;
    private int $listRequestDelaySeconds;
    private int $statsRequestDelaySeconds;
    private float $requestTimeout;
    private array $config;

    /** @param array{client_id:string,client_secret:string,user_id:string,api_base_url?:string,request_timeout?:float} $config */
    public function __construct(array $config)
    {
        $this->config = $config;
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $this->userId = trim((string) ($config['user_id'] ?? ''));
        $this->apiBaseUrl = rtrim((string) ($config['api_base_url'] ?? 'https://api.avito.ru'), '/');
        $this->listRequestDelaySeconds = max(1, (int) ceil((float) ($config['rate_limit_delay'] ?? 8)));
        $this->requestTimeout = max(5.0, (float) ($config['request_timeout'] ?? 120.0));

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('Set AVITO_CLIENT_ID and AVITO_CLIENT_SECRET in .env.');
        }
        if ($this->userId === '') {
            throw new \RuntimeException('Set AVITO_USER_ID in .env.');
        }

        $this->provider = new Avito([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
        ]);

        $this->statsRequestDelaySeconds = max(1, (int) ceil((float) ($config['stats_request_delay_seconds'] ?? 8)));
    }

    /** Obtain a client-credentials token through avito/oauth2-avito. */
    public function getToken(): AccessTokenInterface
    {
        if ($this->accessToken === null || $this->accessToken->hasExpired()) {
            $this->accessToken = $this->provider->getAccessToken('client_credentials');
        }

        return $this->accessToken;
    }

    /** @return array<string, mixed> */
    public function getItemById(int $itemId): array
    {
        if ($itemId <= 0) {
            throw new \InvalidArgumentException('Item ID must be a positive integer.');
        }

        return $this->requestJson('GET', '/core/v1/items/' . $itemId);
    }

    /**
     * Request item statistics grouped by day or as one period total.
     *
     * @param list<int> $itemIds
     * @return list<array<string, mixed>>
     */
    public function getStatsV2(array $itemIds, string $dateFrom, string $dateTo, string $grouping = 'item'): array
    {
        if ($itemIds === []) {
            return [];
        }

        $payload = [
            'itemIds' => array_values($itemIds),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'grouping' => $grouping,
        ];

        $response = $this->requestJson('POST', sprintf('/stats/v1/accounts/%s/items', rawurlencode($this->userId)), $payload);

        return $response['result']['items'] ?? $response['items'] ?? [];
    }

    /** @param list<int> $itemIds @return list<array<string, mixed>> */
    public function getStats(array $itemIds, string $dateFrom, string $dateTo): array
    {
        return $this->getStatsV2($itemIds, $dateFrom, $dateTo);
    }

    /**
     * Запрос статистики с паузой и разбивкой по месяцам.
     *
     * @param list<int> $itemIds
     * @return list<array<string, mixed>>
     */
    public function getStatsWithDelay(array $itemIds, string $dateFrom, string $dateTo, string $grouping = 'item'): array
    {
        // Пауза перед первым запросом
        if ($this->statsRequestDelaySeconds > 0) {
            sleep($this->statsRequestDelaySeconds);
        }

        // Разбиваем период на чанки по месяцам
        $chunks = $this->splitPeriodIntoMonths($dateFrom, $dateTo);
        $allStats = [];

        foreach ($chunks as $chunkIndex => [$chunkFrom, $chunkTo]) {
            $chunkStats = $this->getStatsV2($itemIds, $chunkFrom, $chunkTo, $grouping);
            $allStats = array_merge($allStats, $chunkStats);

            // Пауза между чанками
            if ($chunkIndex < count($chunks) - 1 && $this->statsRequestDelaySeconds > 0) {
                sleep($this->statsRequestDelaySeconds);
            }
        }

        return $allStats;
    }

    /**
     * Разбить период на чанки по месяцам (макс. 270 дней на чанк).
     *
     * @return list<array{0:string, 1:string}>
     */
    public static function splitPeriodIntoMonths(string $dateFrom, string $dateTo): array
    {
        $chunks = [];
        $current = new \DateTimeImmutable($dateFrom);
        $end = new \DateTimeImmutable($dateTo);

        while ($current <= $end) {
            $lastDay = (clone $current)->modify('last day of this month 23:59:59');
            $chunkEnd = $lastDay < $end ? $lastDay : $end;
            $chunks[] = [$current->format('Y-m-d'), $chunkEnd->format('Y-m-d')];
            $current = $chunkEnd->modify('+1 day');
        }

        return $chunks;
    }

    /**
     * Get a list of items with pagination.
     *
     * @param list<string> $statuses Filter by statuses (e.g. ['active'])
     * @return array{resources?: list<array<string, mixed>>, total?: int}
     */
    public function listItems(array $statuses = ['active'], int $page = 1, int $perPage = 50): array
    {
        $query = array_merge(
            ['status' => implode(',', $statuses), 'per_page' => $perPage, 'page' => $page],
            ['user_id' => $this->userId]
        );

        return $this->requestJson('GET', '/core/v1/items', $query);
    }

    /**
     * Get ALL items (paginated) with a given status.
     *
     * @param list<string> $statuses
     * @return list<array<string, mixed>>
     */
    public function getAllItems(array $statuses = ['active'], int $perPage = 100, ?callable $onPage = null): array
    {
        $all = [];
        $page = 1;
        $totalFetched = 0;

        do {
            $result = $this->listItems($statuses, $page, $perPage);
            $resources = $result['resources'] ?? [];

            if ($resources === []) {
                break;
            }

            $all = array_merge($all, $resources);
            $totalFetched += count($resources);

            $total = (int) ($result['total'] ?? 0);
            if ($onPage !== null) {
                $onPage($page, $totalFetched, $total);
            }
            // API не возвращает корректный total (всегда 0).
            // Продолжаем, пока не получим пустую страницу.
            // Прерываемся после 500 страниц (~50000 items) как защита от бесконечного цикла.
            if ($page >= 500) {
                break;
            }

            $page++;
            // GET /core/v1/items ограничен 25 запросами в минуту.
            // Используем ту же консервативную паузу, что и проверочный test_item.php.
            sleep($this->listRequestDelaySeconds);
        } while (true);

        return $all;
    }

    /**
     * Get detailed information about a single item (includes views, contacts, etc.).
     *
     * @return array<string, mixed>
     */
    public function getItemDetail(int $itemId): array
    {
        return $this->requestJson('GET', sprintf('/core/v1/accounts/%s/items/%d', rawurlencode($this->userId), $itemId));
    }

    /**
     * Get full item data from /core/v1/items/{id}
     * Returns fields not available in list endpoint: brand, oem_number, contact_block, etc.
     *
     * @return array<string, mixed>|null
     */
    public function getFullItem(int $itemId): ?array
    {
        try {
            return $this->requestJson('GET', '/core/v1/items/' . $itemId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Count items by status.
     *
     * @return array<string, int>  e.g. ['active' => 12, 'draft' => 3]
     */
    public function countAllStatuses(): array
    {
        $counts = [];
        $statuses = ['active', 'closed', 'draft', 'moderation', 'rejected', 'archived'];

        foreach ($statuses as $index => $status) {
            // Пауза между запросами для разных статусов (лимит 25 req/min)
            if ($index > 0 && $this->listRequestDelaySeconds > 0) {
                sleep($this->listRequestDelaySeconds);
            }
            try {
                $result = $this->listItems([$status], 1, 1);
                $counts[$status] = (int) ($result['total'] ?? 0);
            } catch (\Throwable $e) {
                $counts[$status] = 0;
            }
        }

        return $counts;
    }

    /**
     * Search for an item by its public number (e.g. "8012519823").
     *
     * @return array<string, mixed>|null
     */
    public function searchItemByNumber(string $number): ?array
    {
        try {
            $result = $this->requestJson('GET', '/core/v1/items', [
                'user_id' => $this->userId,
                'id' => $number,
                'per_page' => 1,
            ]);

            $resources = $result['resources'] ?? [];
            if ($resources !== []) {
                return $resources[0];
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Деактивировать объявление на Avito.
     *
     * @return array{success: bool, message?: string}
     */
    public function deactivateItem(int $itemId): array
    {
        try {
            $this->requestJson('POST', sprintf('/core/v1/items/%d/deactivate', $itemId));
            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get images for a specific item.
     *
     * @return list<array{url?: string, slug?: string, thumb_url?: string}>
     */
    public function getImages(int $itemId): array
    {
        try {
            $result = $this->requestJson('GET', '/images/v1/items/' . $itemId . '/images');
            return $result['images'] ?? $result['resources'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function requestJson(string $method, string $path, ?array $json = null): array
    {
        $maxRetries = (int) ($this->config['max_retries'] ?? 3);
        $retryDelayBase = (int) ($this->config['retry_delay_base'] ?? 65);

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $options = [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => $this->requestTimeout,
            ];
            if ($json !== null) {
                if (strtoupper($method) === 'GET') {
                    $query = http_build_query($json, '', '&', PHP_QUERY_RFC3986);
                    if ($query !== '') {
                        $path .= '?' . $query;
                    }
                } else {
                    $options['headers']['Content-Type'] = 'application/json';
                    $options['body'] = json_encode($json, JSON_THROW_ON_ERROR);
                }
            }

            try {
                $request = $this->provider->getAuthenticatedRequest(
                    $method,
                    $this->apiBaseUrl . $path,
                    $this->getToken(),
                    $options
                );
                $response = $this->provider->getResponse($request);
                return $this->decodeResponse($response);
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                $response = $e->getResponse();
                $statusCode = $response ? $response->getStatusCode() : 0;

                // Retry только для 429 (Too Many Requests) и 5xx
                if (($statusCode === 429 || ($statusCode >= 500 && $statusCode < 600)) && $attempt < $maxRetries) {
                    $delay = $retryDelayBase * ((int) pow(2, $attempt));
                    fwrite(STDERR, "  [RETRY #$attempt] HTTP $statusCode — ждём {$delay} сек...\n");
                    sleep($delay);
                    continue;
                }

                throw $e;
            }
        }

        // Should not reach here, but just in case
        throw new \RuntimeException("requestJson failed after $maxRetries retries");
    }

    /** @return array<string, mixed> */
    private function decodeResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $data = $body === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if ($response->getStatusCode() >= 400) {
            $message = is_array($data) ? (string) ($data['message'] ?? $body) : $body;
            throw new \RuntimeException(sprintf('Avito API returned HTTP %d: %s', $response->getStatusCode(), $message));
        }

        return is_array($data) ? $data : [];
    }
}
