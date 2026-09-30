<?php

declare(strict_types=1);

namespace App\Accounts;

/**
 * Код кабинета в путях, реестре и командах.
 * Строчные буквы, чтобы каталоги на Windows и Linux совпадали.
 */
final class AccountCode
{
    public const PATTERN = '/^[a-z0-9][a-z0-9_-]{0,40}$/';

    /** @var list<string> */
    private const RESERVED = ['add', 'list'];

    public static function assertValid(string $code): string
    {
        $code = trim($code);
        if (!preg_match(self::PATTERN, $code)) {
            throw new \InvalidArgumentException(
                'Код аккаунта: строчные латинские буквы, цифры, _ и -, до 41 символа, начинается с буквы или цифры.'
            );
        }
        if (in_array($code, self::RESERVED, true)) {
            throw new \InvalidArgumentException('Коды add и list заняты командами реестра.');
        }

        return $code;
    }
}
