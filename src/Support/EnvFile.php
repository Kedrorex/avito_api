<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Точечная правка одного ключа в env-файле кабинета.
 * Остальные строки, включая секреты и комментарии, остаются как были.
 */
final class EnvFile
{
    public static function set(string $path, string $key, string $value): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            throw new \InvalidArgumentException("Некорректный ключ {$key}");
        }
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new \InvalidArgumentException('Значение не должно быть многострочным.');
        }
        if (!is_file($path)) {
            throw new \RuntimeException("Нет файла {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Не удалось прочитать {$path}");
        }

        $newline = str_contains($raw, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $raw);
        if ($lines === false) {
            throw new \RuntimeException("Не удалось разобрать {$path}");
        }
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $written = $key . '=' . $value;
        $found = false;
        foreach ($lines as $index => $line) {
            if (self::keyOf($line) !== $key) {
                continue;
            }
            $lines[$index] = $written;
            $found = true;
        }
        if (!$found) {
            $lines[] = $written;
        }

        $result = file_put_contents($path, implode($newline, $lines) . $newline);
        if ($result === false) {
            throw new \RuntimeException("Не удалось записать {$path}");
        }
    }

    private static function keyOf(string $line): ?string
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            return null;
        }

        return trim(substr($line, 0, $eq));
    }
}
