<?php

/**
 * Helper для получения переменных окружения
 * Работает с phpdotenv в PHP 8.2+
 */
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        // Проверяем $_ENV (phpdotenv загружает сюда)
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        // Проверяем $_SERVER
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        // Пробуем getenv
        $value = getenv($key);
        if ($value !== false && $value !== null) {
            return $value;
        }

        return $default;
    }
}
