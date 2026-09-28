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
        'stats_request_delay_seconds' => 8,
        // Таймаут HTTP-запросов к Avito API (секунды)
        'request_timeout' => 120,

        // Republisher settings
        'max_daily_repub'   => 70,
        'min_age_days'      => 3,
        'stats_days'        => 3,
        'contact_threshold' => 0,

        // Candidates settings (zero-view detection)
        'candidate_days'      => 4,    // дней для анализа
        'candidate_threshold' => 0,    // порог просмотров (0)

        // Автозагрузка: пакетный запрос Id объявления из файла
        'autoload_id_batch_size' => 100,
        'autoload_request_delay_seconds' => 1,
    ],

    // Анализ и пороги для кандидатов на переопубликовку
    'analysis_thresholds' => [
        [
            'name' => 'zero_contacts',
            'days' => 5,
            'max_views' => 0,
            'max_contacts' => 0,
            'max_favorites' => 0,
        ],
        [
            'name' => 'low_views',
            'days' => 10,
            'max_views' => 1,
            'max_contacts' => 0,
            'max_favorites' => 0,
        ],
    ],

    // Feed settings (Avito AutoLoad)
    'feed' => [
        // Каталог для выгрузки TSV файлов
        'output_dir'       => __DIR__ . '/../fid',

        // Категория фида (соответствует шаблону Avito)
        'category'         => 'Транспорт - Запчасти и аксессуары - Запчасти - Для автомобилей - Двигатель',

        // Значения по умолчанию для обязательных полей
        'default_views'         => 'Package',          // Способ размещения
        'default_ad_type'       => '',                 // Вид объявления
        'default_product_type'  => '',                 // Тип товара
        'default_part_type'     => '',                 // Вид запчасти
        'default_engine_type'   => '',                 // Тип детали двигателя
        'default_condition'     => 'new',              // Состояние: new / used / restored

        // Контактные данные компании
        'company_name'          => '',                 // Название компании
        'email'                 => '',                 // Почта

        // Источник данных для AutoLoad (импорт из CSV)
        'autoload_source'       => __DIR__ . '/../fid/Основная база.csv',
    ],

    // SQLite database
    'database' => [
        'dsn'    => 'sqlite:' . __DIR__ . '/../data/avito.db',
        'options' => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // SQLite: ждать до 10 сек при блокировке (1001 = PDO::SQLITE_ATTR_BUSY_TIMEOUT, PHP 8.1+)
            1001 => 10000,
        ],
    ],
];
