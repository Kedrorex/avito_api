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

    /** @param array{client_id:string,client_secret:string,user_id:string,api_base_url?:string} $config */
    public function __construct(array $config)
    {
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $this->userId = trim((string) ($config['user_id'] ?? ''));
        $this->apiBaseUrl = rtrim((string) ($config['api_base_url'] ?? 'https://api.avito.ru'), '/');

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

        $payload = $this->requestJson('POST', sprintf('/stats/v1/accounts/%s/items', rawurlencode($this->userId)), [
            'itemIds' => array_values($itemIds),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'grouping' => $grouping,
        ]);

        return $payload['result']['items'] ?? $payload['items'] ?? [];
    }

    /** @param list<int> $itemIds @return list<array<string, mixed>> */
    public function getStats(array $itemIds, string $dateFrom, string $dateTo): array
    {
        return $this->getStatsV2($itemIds, $dateFrom, $dateTo);
    }

    /** @return array<string, mixed> */
    private function requestJson(string $method, string $path, ?array $json = null): array
    {
        $options = ['headers' => ['Accept' => 'application/json']];
        if ($json !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($json, JSON_THROW_ON_ERROR);
        }

        $request = $this->provider->getAuthenticatedRequest(
            $method,
            $this->apiBaseUrl . $path,
            $this->getToken(),
            $options
        );
        $response = $this->provider->getResponse($request);

        return $this->decodeResponse($response);
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
