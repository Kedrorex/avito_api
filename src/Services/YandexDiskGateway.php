<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Запросы к REST API Диска. Токен передаётся на вызов и в клиенте не хранится.
 */
interface YandexDiskGateway
{
    /**
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query, string $token): array;

    public function putFile(string $url, string $localFile): void;

    /**
     * @return array<string, mixed>
     */
    public function publicResource(string $publicUrl): array;
}
