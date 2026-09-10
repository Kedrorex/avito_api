<?php

/**
 * Конфигурация Avito API
 */

return [
    // Avito API credentials
    'avito' => [
        'client_id'     => env('AVITO_CLIENT_ID', ''),
        'client_secret' => env('AVITO_CLIENT_SECRET', ''),
        'user_id'       => env('AVITO_USER_ID', ''),
        'api_base_url'  => 'https://api.avito.ru',
        'token_url'     => 'https://api.avito.ru/token/',

        // Rate limiting: ~25 req/min (8 сек между запросами)
        'rate_limit_delay'    => 8.0,
        'max_requests_per_min' => 25,
        // Все статусы, поддержанные GET /core/v1/items в текущем Swagger.
        // Без этого фильтра API по умолчанию возвращает только active.
        'item_statuses' => ['active', 'removed', 'old', 'blocked', 'rejected'],

        // Pagination defaults
        'per_page'      => 50,
        'max_per_page'  => 100,

        // Retry settings
        'max_retries'       => 3,
        'retry_delay_base'  => 65, // секунды для ConnectionResetError

        // Stats
        'stats_fields' => [
            'views', 'uniqViews',
            'contacts', 'uniqContacts',
            'favorites', 'uniqFavorites',
            'contactsShowPhone',
            'contactsMessenger',
        ],
        // Пауза между пакетными запросами статистики
        // API: 1 запрос в минуту → 65 сек как запас
        'stats_request_delay_seconds' => 65,

        // Republisher settings
        'max_daily_repub'   => 70,
        'min_age_days'      => 3,
        'stats_days'        => 3,
        'contact_threshold' => 0,
    ],

    // SQLite database
    'database' => [
        'dsn'    => 'sqlite:' . __DIR__ . '/../data/avito.db',
        'options' => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    ],
];
