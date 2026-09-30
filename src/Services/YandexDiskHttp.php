<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

/**
 * Запросы к REST API Диска. Токен передаётся на вызов и в клиенте не хранится.
 */
final class YandexDiskHttp implements YandexDiskGateway
{
    private Client $client;

    public function __construct(private readonly float $timeout = 120.0)
    {
        $this->client = new Client([
            'base_uri' => 'https://cloud-api.yandex.net',
            'timeout' => $this->timeout,
            'http_errors' => false,
        ]);
    }

    public function get(string $path, array $query, string $token): array
    {
        $response = $this->client->request('GET', $path, [
            'headers' => $this->authHeaders($token),
            'query' => $query,
        ]);

        return $this->decode($response);
    }

    public function putFile(string $url, string $localFile): void
    {
        $handle = fopen($localFile, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось прочитать {$localFile}");
        }

        try {
            $response = $this->client->request('PUT', $url, [
                'body' => $handle,
                'headers' => ['Content-Type' => 'application/octet-stream'],
                'timeout' => max($this->timeout, 300.0),
            ]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $body = trim((string) $response->getBody());
            $suffix = $body !== '' ? ': ' . $body : '';
            throw new \RuntimeException("Яндекс.Диск не принял файл, HTTP {$status}{$suffix}");
        }
    }

    public function publicResource(string $publicUrl): array
    {
        $response = $this->client->request('GET', '/v1/disk/public/resources', [
            'headers' => ['Accept' => 'application/json'],
            'query' => ['public_key' => $publicUrl],
        ]);

        return $this->decode($response);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $token): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'OAuth ' . $token,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $data = $body === '' ? [] : json_decode($body, true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($status < 400) {
            return $data;
        }

        if ($status === 401) {
            throw new \RuntimeException('Яндекс.Диск отклонил токен. Выпустите новый и впишите его в YANDEX_DISK_TOKEN.');
        }
        if ($status === 403) {
            throw new \RuntimeException(
                'У токена нет права записи на Диск. Нужен доступ «чтение и запись всего Диска»: папки приложения недостаточно, файл уже лежит в Диске владельца.'
            );
        }

        $message = trim((string) ($data['description'] ?? $data['message'] ?? $body));
        if ($message === '') {
            $message = 'пустой ответ';
        }

        throw new \RuntimeException("Яндекс.Диск ответил HTTP {$status}: {$message}");
    }
}
