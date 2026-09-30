<?php

declare(strict_types=1);

namespace App\Accounts;

/**
 * Замкнутый контур одного кабинета. Секреты живут только здесь, на время прогона.
 */
final class AccountContext
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $userId,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $dbPath,
        public readonly string $feedDir,
        public readonly string $envFile,
        public readonly bool $enabled,
        public readonly ?int $maxDailyRepub,
        public readonly string $yandexDiskToken = '',
        public readonly string $yandexDiskPublicUrl = '',
        public readonly string $yandexDiskPath = '',
        public readonly string $yandexDiskClientId = '',
        public readonly string $yandexDiskClientSecret = '',
    ) {
    }
}
