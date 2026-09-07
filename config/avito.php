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

        // Pagination defaults
        'per_page'      => 50,
        'max_per_page'  => 100,

        // Retry settings
        'max_retries'       => 3,
        'retry_delay_base'  => 60, // секунды для ConnectionResetError

        // Stats
        'stats_fields' => [
            'views', 'uniqViews',
            'contacts', 'uniqContacts',
            'favorites', 'uniqFavorites',
        ],

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
