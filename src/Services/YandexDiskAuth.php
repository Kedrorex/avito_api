<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;

/**
 * Код подтверждения Яндекса обменивается на токен Диска.
 * Client ID и секрет сами по себе файл не перезаписывают.
 */
final class YandexDiskAuth
{
    public const REDIRECT_URI = 'https://oauth.yandex.ru/verification_code';

    public static function authorizeUrl(string $clientId): string
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            throw new \InvalidArgumentException('В .env нет YANDEX_DISK_CLIENT_ID.');
        }

        return 'https://oauth.yandex.ru/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, refresh_token: string}
     */
    public static function exchange(string $clientId, string $clientSecret, string $code): array
    {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        $code = trim($code);
        if ($clientId === '' || $clientSecret === '') {
            throw new \InvalidArgumentException('В .env нужны YANDEX_DISK_CLIENT_ID и YANDEX_DISK_CLIENT_SECRET.');
        }
        if ($code === '') {
            throw new \InvalidArgumentException('Нет кода подтверждения.');
        }

        $client = new Client(['timeout' => 30, 'http_errors' => false]);
        $response = $client->request('POST', 'https://oauth.yandex.ru/token', [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => self::REDIRECT_URI,
            ],
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $data = $body === '' ? [] : json_decode($body, true);
        if (!is_array($data)) {
            $data = [];
        }
        if ($status >= 400) {
            $message = trim((string) ($data['error_description'] ?? $data['error'] ?? $body));
            if ($message === '') {
                $message = 'пустой ответ';
            }
            throw new \RuntimeException("Яндекс не выдал токен, HTTP {$status}: {$message}");
        }

        $token = trim((string) ($data['access_token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('Яндекс не вернул access_token.');
        }

        return [
            'access_token' => $token,
            'refresh_token' => trim((string) ($data['refresh_token'] ?? '')),
        ];
    }
}
