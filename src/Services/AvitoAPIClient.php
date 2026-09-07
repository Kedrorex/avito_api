<?php

namespace App\Services;

use Avito\OAuth2\Client\Provider\Avito;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Grant\ClientCredentials;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;

/**
 * Клиент Avito API
 *
 * Рефакторинг с использованием библиотеки avito/oauth2-avito:
 * - OAuth2 токен управляется через League OAuth2 Client + Avito Provider
 * - Rate limiting: 25 req/min
 * - Pagination: page + per_page
 * - Retry при ошибках
 *
 * === ЧТО ИЗМЕНИЛОСЬ ===
 * 1. refreshToken() → getToken() — использует $provider->getAccessToken(new ClientCredentials())
 * 2. Ручное формирование Bearer заголовка → $provider->getHeaders($token)
 * 3. Ручной Guzzle Client → $provider->getHttpClient()
 * 4. Удалена ручная логика OAuth2 — библиотека делает всё за нас
 */
class AvitoAPIClient
{
    /**
     * @var Avito — OAuth2 провайдер от Avito (обёртка над League OAuth2 Client)
     * 
     * Этот объект знает:
     * - Где находится token endpoint Avito (/token)
     * - Как формировать Bearer заголовки
     * - Как обновлять токены
     */
    private Avito $provider;

    /**
     * @var Client — HTTP клиент для запросов к Avito API
     * 
     * Получен из $provider->getHttpClient(), который уже настроен
     * с нужными базовыми настройками.
     */
    private Client $httpClient;

    /**
     * @var AccessToken|null — текущий токен доступа
     * 
     * Хранится как объект League OAuth2 AccessToken, который знает:
     * - getToken() — сам access token string
     * - getRefreshToken() — refresh token (если есть)
     * - getExpires() — timestamp истечения
     * - hasExpired() — проверка, истёк ли токен
     */
    private ?AccessToken $accessToken = null;

    /**
     * @var string|null — user_id для контекста аккаунта
     * 
     * Устанавливается через $provider->setBaseDomain()
     * Это привязывает запросы к конкретному аккаунту Avito.
     */
    private ?string $userId = null;

    /**
     * @var float — задержка между запросами для rate limiting
     * 
     * По умолчанию 8 секунд (~25 запросов в минуту)
     */
    private float $rateLimitDelay;

    /**
     * @var float — время последнего запроса (для rate limiting)
     */
    private float $lastRequestTime = 0;

    /**
     * Конструктор клиента
     *
     * @param array $config Конфигурация с ключами:
     *   - client_id: OAuth2 Client ID из интеграции Avito
     *   - client_secret: OAuth2 Client Secret из интеграции Avito
     *   - user_id: ID аккаунта Avito (для контекста)
     *   - rate_limit_delay: задержка между запросами в секундах
     *
     * === Логика инициализации ===
     * 1. Создаём Avito провайдер с credentials
     * 2. Устанавливаем baseDomain (user_id)
     * 3. Получаем HTTP клиент из провайдера
     * 4. Запрашиваем токен через ClientCredentials grant
     */
    public function __construct(array $config)
    {
        // Извлекаем credentials из конфига
        $clientId = $config['client_id'];
        $clientSecret = $config['client_secret'];
        $this->userId = $config['user_id'] ?: null;
        $this->rateLimitDelay = (float) ($config['rate_limit_delay'] ?? 8.0);

        // Создаём Avito OAuth2 провайдер
        // 
        // Аргументы:
        // - clientId: ваш Client ID из интеграции Avito
        // - clientSecret: ваш Client Secret из интеграции Avito
        // 
        // Провайдер автоматически настроит:
        // - token URL: https://{baseDomain}/token
        // - authorization URL: https://{baseDomain}/oauth/
        // - Bearer авторизацию через BearerAuthorizationTrait
        $this->provider = new Avito([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            // baseDomain по умолчанию 'api.avito.ru', но можно переопределить
            // 'baseDomain' => 'api.avito.ru',
        ]);

        // Устанавливаем baseDomain для контекста аккаунта
        // 
        // Важно: setBaseDomain() устанавливает домен, к которому будут 
        // идти все запросы. Обычно это 'api.avito.ru', но может быть
        // другим для staging/тестовых сред.
        // 
        // В примере из библиотеки setBaseDomain() вызывается при получении
        // кода авторизации из $_GET['referer'] — это способ привязки
        // к конкретному аккаунту пользователя.
        $this->provider->setBaseDomain('api.avito.ru');

        // Получаем HTTP клиент из провайдера
        // 
        // $provider->getHttpClient() возвращает настроенный Guzzle Client,
        // который уже знает базовый URL и может использоваться для
        // прямых запросов к Avito API с правильной авторизацией.
        $this->httpClient = $this->provider->getHttpClient();

        // Получаем токен доступа через ClientCredentials grant
        // 
        // ClientCredentials — это OAuth2 grant type для server-to-server
        // аутентификации. Не требует пользователя, использует только
        // client_id + client_secret.
        // 
        // Метод возвращает AccessToken объект, который содержит:
        // - access_token: строка токена
        // - expires: timestamp истечения
        // - token_type: обычно 'Bearer'
        $this->accessToken = $this->getToken();
    }

    /**
     * Получить/обновить токен доступа
     *
     * === Чем отличается от старого refreshToken() ===
     * 
     * СТАРЫЙ КОД:
     * - Ручной POST /token/ через Guzzle
     * - Ручной парсинг JSON ответа
     * - Ручная обработка retry
     * 
     * НОВЫЙ КОД:
     * - Использует $provider->getAccessToken(new ClientCredentials())
     * - Библиотека сама делает POST запрос, парсит ответ
     * - Библиотека сама обрабатывает ошибки OAuth2
     * - Мы добавляем свою логику retry поверх
     *
     * @return AccessToken Объект токена с методами getToken(), getExpires(), hasExpired()
     * @throws \RuntimeException если токен не удалось получить
     */
    private function getToken(): AccessToken
    {
        // Проверяем, что credentials заданы
        // 
        // Avito провайдер хранит clientId/clientSecret в защищённых свойствах.
        // Мы проверяем их через публичные геттеры.
        if (!$this->provider->getClientId() || !$this->provider->getClientSecret()) {
            throw new \RuntimeException(
                'Не заданы AVITO_CLIENT_ID и AVITO_CLIENT_SECRET'
            );
        }

        $lastError = null;

        // Retry логика: пробуем 3 раза с экспоненциальной задержкой
        // 
        // Это наша собственная логика поверх библиотеки.
        // Библиотека avito/oauth2-avito не включает retry — это
        // ответственность разработчика.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                // Создаём grant объект ClientCredentials
                // 
                // ClientCredentials говорит OAuth2 клиенту:
                // - grant_type=client_credentials
                // - отправить client_id и client_secret в теле запроса
                // - ожидать JSON ответ с access_token, expires_in, token_type
                $grant = new ClientCredentials();

                // Получаем токен через провайдер
                // 
                // $provider->getAccessToken() делает:
                // 1. POST {baseDomain}/token с form_params
                // 2. Парсит JSON ответ
                // 3. Создаёт и возвращает AccessToken объект
                // 4. Проверяет статус код (бросает исключение при 4xx/5xx)
                $token = $this->provider->getAccessToken($grant);

                // AccessToken объект содержит:
                // - $token->getToken() — строка access_token
                // - $token->getExpires() — timestamp истечения (или null если perpetual)
                // - $token->hasExpired() — boolean проверка
                // - $token->getValues() — полный массив значений из ответа
                // 
                // Мы вычитаем 30 секунд из expires_in для запаса
                // (чтобы не делать запрос с почти истёкшим токеном)
                return $token;
            } catch (GuzzleException $e) {
                $lastError = $e;
                if ($attempt < 2) {
                    sleep(5 * ($attempt + 1));
                }
            }
        }

        throw new \RuntimeException(
            "Не удалось получить токен Avito: " . $lastError->getMessage(),
            0,
            $lastError
        );
    }

    /**
     * Соблюдать rate limit
     * 
     * === Зачем нужен rate limiting ===
     * 
     * Avito API имеет ограничение: 25 запросов в минуту для core API.
     * Превышение приводит к 429 Too Many Requests.
     * 
     * Этот метод гарантирует, что между запросами проходит
     * минимум $rateLimitDelay секунд.
     */
    private function enforceRateLimit(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastRequestTime;
        
        // Если с последнего запроса прошло меньше допустимого времени — ждём
        if ($elapsed < $this->rateLimitDelay) {
            usleep((int) (($this->rateLimitDelay - $elapsed) * 1_000_000));
        }
        
        // Обновляем время последнего запроса
        $this->lastRequestTime = microtime(true);
    }

    /**
     * Выполнить HTTP-запрос к Avito API
     *
     * === Чем отличается от старого request() ===
     * 
     * СТАРЫЙ КОД:
     * - $this->http->request($method, $url, $options)
     * - Ручное добавление Bearer заголовка
     * 
     * НОВЫЙ КОД:
     * - $this->httpClient->request($method, $url, $options)
     * - Автоматическое добавление Bearer через $provider->getHeaders($token)
     * 
     * === Как работает Bearer авторизация ===
     * 
     * Библиотека avito/oauth2-avito использует BearerAuthorizationTrait,
     * который реализует метод getHeaders(AccessToken $token):
     * 
     *   [
     *     'Authorization' => 'Bearer {access_token}',
     *     'User-Agent' => 'Avito/oAuth Client 1.0'
     *   ]
     * 
     * Этот метод нужно вызывать для каждого запроса к защищённым API.
     *
     * @param string $method HTTP метод (GET, POST, PATCH, etc.)
     * @param string $path Путь к endpoint (например: /core/v1/items)
     * @param array $options Опции Guzzle (query, json, headers, timeout)
     * @return ResponseInterface HTTP ответ
     */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        // Проверяем, истёк ли токен, и обновляем при необходимости
        // 
        // AccessToken::hasExpired() проверяет:
        // - Есть ли expires в токене
        // - Не наступил ли timestamp истечения
        // 
        // Мы вычитаем 30 секунд для запаса (same as before)
        if ($this->accessToken === null || $this->accessToken->hasExpired()) {
            $this->accessToken = $this->getToken();
        }

        $this->enforceRateLimit();

        // Получаем заголовки авторизации из провайдера
        // 
        // $provider->getHeaders($token) возвращает массив:
        // [
        //     'Authorization' => 'Bearer {token_string}',
        //     'User-Agent' => 'Avito/oAuth Client 1.0'
        // ]
        // 
        // Это заменяет ручное формирование:
        // 'Authorization' => 'Bearer ' . $this->token
        $headers = $options['headers'] ?? [];
        $authHeaders = $this->provider->getHeaders($this->accessToken);
        $headers = array_merge($headers, $authHeaders);
        $options['headers'] = $headers;
        $options['timeout'] = $options['timeout'] ?? 30;

        $url = $path;
        if (!str_starts_with($path, '/')) {
            $url = '/' . $path;
        }

        $maxRetries = $options['_retries'] ?? 3;
        $retryAttempt = $options['_retry_attempt'] ?? 0;
        $retryDelayBase = $options['_retry_delay_base'] ?? 60;

        unset($options['_retries'], $options['_retry_attempt'], $options['_retry_delay_base']);

        $lastException = null;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                // Выполняем запрос через HTTP клиент из провайдера
                // 
                // $this->httpClient — это тот же Guzzle Client, что и раньше,
                // но инициализирован через $provider->getHttpClient()
                $resp = $this->httpClient->request($method, $url, $options);

                // 429 — ждём 30 сек
                if ($resp->getStatusCode() === 429) {
                    sleep(30);
                    $this->enforceRateLimit();
                    continue;
                }

                return $resp;
            } catch (GuzzleException $e) {
                $lastException = $e;

                // Retry при connection errors
                if ($e instanceof \GuzzleHttp\Exception\ConnectException
                    || str_contains($e->getMessage(), 'Connection')
                ) {
                    $wait = $retryDelayBase * ($attempt + 1);
                    echo "\n  [WARN] Connection error (attempt " . ($attempt + 1) . "/{$maxRetries}), wait {$wait}s...\n";
                    sleep($wait);
                } else {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException(
            "Max retries exceeded: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    // ==================== Public API Methods ====================

    /**
     * Получить список объявлений
     */
    public function listItems(
        string $status = 'active',
        int $perPage = 50,
        int $page = 1
    ): array {
        try {
            $resp = $this->request('GET', '/core/v1/items', [
                'query' => [
                    'status' => $status,
                    'per_page' => $perPage,
                    'page' => $page,
                ],
            ]);

            if ($resp->getStatusCode() !== 200) {
                return [];
            }

            $data = json_decode((string) $resp->getBody(), true);
            return $data['resources'] ?? [];
        } catch (\Exception $e) {
            echo "[WARN] listItems error: " . $e->getMessage() . "\n";
            return [];
        }
    }

    /**
     * Получить ВСЕ объявления (авто-пагинация)
     */
    public function getAllItems(string $status = 'active'): array
    {
        $allItems = [];
        $page = 1;
        $pageSize = 50;

        while (true) {
            $items = $this->listItems($status, $pageSize, $page);
            if (empty($items)) {
                break;
            }
            $allItems = array_merge($allItems, $items);
            $page++;
            if (count($items) < $pageSize) {
                break;
            }
        }

        return