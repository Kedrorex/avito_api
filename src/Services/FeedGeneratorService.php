<?php

namespace App\Services;

use App\Repositories\ItemRepository;

/**
 * Генератор фида для Avito AutoLoad (CSV TSV)
 *
 * Формат: TSV (Tab-Separated Values)
 * Категория: Транспорт - Запчасти и аксессуары - Запчасти - Для автомобилей - Двигатель
 *
 * Обязательные поля:
 * - Адрес
 * - Уникальный идентификатор
 * - Категория
 * - Описание объявления
 * - Название объявления
 * - Цена
 * - Вид товара
 * - Вид объявления
 * - Тип товара
 * - Вид запчасти
 * - Тип детали двигателя
 * - Состояние
 */
class FeedGeneratorService
{
    private ItemRepository $repository;
    private array $config;
    private string $outputDir;

    /**
     * @param array{
     *     feed?: array{
     *         output_dir?: string,
     *         category?: string,
     *         default_views?: string,
     *         default_ad_type?: string,
     *         default_product_type?: string,
     *         default_part_type?: string,
     *         default_engine_type?: string,
     *         default_condition?: string,
     *     }
     * } $config
     */
    public function __construct(
        ItemRepository $repository,
        array $config
    ) {
        $this->repository = $repository;
        $this->config = $config;
        $this->outputDir = $config['feed']['output_dir'] ?? __DIR__ . '/../../fid';
    }

    /**
     * Сгенерировать TSV файл с объявлениями
     *
     * @param bool $priorityMode Если true — кандидаты на переопубликовку идут первыми
     *
     * @return array{file: string, count: int, headers: string[]}
     */
    public function generate(bool $priorityMode = true): array
    {
        // Получаем все активные объявления
        $activeAds = $this->repository->getActive();

        if (empty($activeAds)) {
            echo "  Нет активных объявлений для выгрузки\n";
            return ['file' => '', 'count' => 0, 'headers' => []];
        }

        // Формируем имя файла с датой
        $date = date('Y-m-d');
        $filename = "avito_feed_{$date}.tsv";
        $filepath = $this->outputDir . '/' . $filename;

        // Создаём директорию если не существует
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        // Заголовки TSV (из шаблона Avito)
        $headers = $this->getHeaders();

        // Открываем файл для записи
        $handle = fopen($filepath, 'w', false);
        if ($handle === false) {
            throw new \RuntimeException("Не удалось создать файл фида: {$filepath}");
        }

        // Записываем заголовки
        fputcsv($handle, $headers, "\t");

        // Приоритетный режим: сортируем кандидатов первыми
        $ordered = $activeAds;
        $analysisMap = [];

        if ($priorityMode) {
            $analysis = new AnalysisService($this->repository, $this->config);
            $candidates = $analysis->findAllCandidates();

            // Создаём мапу avito_id -> analysis
            foreach ($candidates as $c) {
                $avitoId = (string) ($c['ad']['avito_id'] ?? '');
                if ($avitoId !== '') {
                    $analysisMap[$avitoId] = $c['analysis'];
                }
            }

            // Разделяем на кандидатов и обычных
            $candidateAds = [];
            $normalAds = [];
            foreach ($activeAds as $ad) {
                $avitoId = (string) ($ad['avito_id'] ?? '');
                if (isset($analysisMap[$avitoId])) {
                    $candidateAds[] = [
                        'ad' => $ad,
                        'analysis' => $analysisMap[$avitoId],
                    ];
                } else {
                    $normalAds[] = ['ad' => $ad, 'analysis' => null];
                }
            }

            // Кандидаты первыми, затем обычные
            $ordered = array_merge($candidateAds, $normalAds);
        }

        // Записываем данные
        $count = 0;
        foreach ($ordered as $item) {
            $ad = is_array($item) && isset($item['ad']) ? $item['ad'] : $item;
            $analysis = is_array($item) && isset($item['analysis']) ? $item['analysis'] : null;
            $row = $this->buildRow($ad, $analysis);
            if ($row !== null) {
                fputcsv($handle, $row, "\t");
                $count++;
            }
        }

        fclose($handle);

        echo "  Файд создан: {$filepath} ({$count} объявлений)\n";

        return ['file' => $filepath, 'count' => $count, 'headers' => $headers];
    }

    /**
     * Получить список заголовков TSV
     *
     * @return string[]
     */
    private function getHeaders(): array
    {
        return [
            'Адрес',
            'Широта',
            'Долгота',
            'Приоритет',
            'Правила',
            'Уникальный идентификатор объявления',
            'Начало размещения',
            'Окончание размещения',
            'Способ размещения',
            'Услуга продвижения',
            'Номер объявления на Авито',
            'Контактное лицо',
            'Номер телефона',
            'Идентификатор адреса',
            'Способ связи',
            'Addresses',
            'Адреса отгрузки',
            'Категория',
            'Описание объявления',
            'Названия фото',
            'Ссылки на фото',
            'Ссылка на видео',
            'Настройка цены целевого действия',
            'Настройка цены целевого действия: автоматическая',
            'Настройка цены целевого действия: ручная',
            'Название объявления',
            'Интернет звонки',
            'Устройства для приёма звонков',
            'Способ доставки',
            'Вес (Для Доставки)',
            'Длина (Для Доставки)',
            'Высота (Для Доставки)',
            'Ширина (Для Доставки)',
            'Возвраты',
            'Субсидирование доставки',
            'Цена',
            'URL видеофайла',
            'Вид товара',
            'Вид объявления',
            'Тип товара',
            'Вид запчасти',
            'Тип детали двигателя',
            'Состояние',
            'Происхождение',
            'Доступность',
            'Производитель',
            'Номер детали OEM',
            'Производитель оригинала',
            'Номер оригинала',
            'Авто для которых подходит запчасть',
            'Включая НДС',
            'Оптовые продажи',
            'Тип минимального заказа',
            'Количество в минимальном заказе',
            'Фасовка',
            'Количество в фасовке',
            'В чём измеряются товары',
            'Скидка за опт',
            'Размер скидки за опт',
        ];
    }

    /**
     * Сформировать строку TSV для одного объявления
     *
     * @param array  $ad       Данные объявления из БД
     * @param array|null $analysis Результат анализа (кандидат + правила)
     * @return array|null Массив значений или null если пропустить
     */
    private function buildRow(array $ad, ?array $analysis = null): ?array
    {
        $masterData = $ad['master_data'] ? json_decode($ad['master_data'], true) : [];

        if (empty($masterData)) {
            return null;
        }

        // Извлекаем данные
        $title = $masterData['title'] ?? '';
        $price = $masterData['price'] ?? 0;
        $location = $masterData['location'] ?? '';
        $url = $masterData['url'] ?? '';
        $category = $masterData['category'] ?? [];
        $categoryName = is_array($category) ? ($category['name'] ?? '') : '';
        $number = $masterData['number'] ?? ($ad['avito_id'] ?? '');

        // Парсим адрес
        $addressParts = $this->parseAddress($location);
        $city = $addressParts['city'] ?? '';
        $street = $addressParts['street'] ?? '';
        $house = $addressParts['house'] ?? '';

        // Формируем уникальные ID
        $uniqueId = $number ?: $ad['avito_id'] ?: "ad_{$ad['id']}";

        // Формируем описание
        $description = $this->buildDescription($title, $masterData, $categoryName);

        // Конфигурация по умолчанию
        $defaultConfig = $this->config['feed'] ?? [];

        // Приоритет и правила
        $isCandidate = $analysis !== null && ($analysis['is_candidate'] ?? false);
        $priority = $isCandidate ? 'Кандидат на переопубликовку' : 'Обычное';
        $matchedRules = $isCandidate ? implode(', ', $analysis['matched_rules'] ?? []) : '';

        return [
            // Обязательные поля
            $addressParts['full'] ?? '',           // Адрес
            '',                                     // Широта
            '',                                     // Долгота
            $priority,                              // Приоритет
            $matchedRules,                          // Правила
            $uniqueId,                              // Уникальный идентификатор
            '',                                     // Начало размещения
            '',                                     // Окончание размещения
            $defaultConfig['default_views'] ?? '',  // Способ размещения
            '',                                     // Услуга продвижения
            $ad['avito_id'] ?? '',                  // Номер объявления на Авито
            '',                                     // Контактное лицо
            '',                                     // Номер телефона
            '',                                     // Идентификатор адреса
            '',                                     // Способ связи
            '',                                     // Addresses
            '',                                     // Адреса отгрузки
            $categoryName ?: 'Запчасти и аксессуары', // Категория
            $description,                           // Описание объявления
            '',                                     // Названия фото
            '',                                     // Ссылки на фото
            '',                                     // Ссылка на видео
            '',                                     // Настройка цены целевого действия
            '',                                     // Настройка цены целевого действия: автоматическая
            '',                                     // Настройка цены целевого действия: ручная
            $title,                                 // Название объявления
            '',                                     // Интернет звонки
            '',                                     // Устройства для приёма звонков
            '',                                     // Способ доставки
            '',                                     // Вес (Для Доставки)
            '',                                     // Длина (Для Доставки)
            '',                                     // Высота (Для Доставки)
            '',                                     // Ширина (Для Доставки)
            '',                                     // Возвраты
            '',                                     // Субсидирование доставки
            $price,                                 // Цена
            '',                                     // URL видеофайла
            $defaultConfig['default_views'] ?? '',  // Вид товара
            $defaultConfig['default_ad_type'] ?? '',// Вид объявления
            $defaultConfig['default_product_type'] ?? '', // Тип товара
            $defaultConfig['default_part_type'] ?? '',    // Вид запчасти
            $defaultConfig['default_engine_type'] ?? '',  // Тип детали двигателя
            $defaultConfig['default_condition'] ?? '',    // Состояние
            '',                                     // Происхождение
            '',                                     // Доступность
            '',                                     // Производитель
            '',                                     // Номер детали OEM
            '',                                     // Производитель оригинала
            '',                                     // Номер оригинала
            '',                                     // Авто для которых подходит запчасть
            '',                                     // Включая НДС
            '',                                     // Оптовые продажи
            '',                                     // Тип минимального заказа
            '',                                     // Количество в минимальном заказе
            '',                                     // Фасовка
            '',                                     // Количество в фасовке
            '',                                     // В чём измеряются товары
            '',                                     // Скидка за опт
            '',                                     // Размер скидки за опт
        ];
    }

    /**
     * Разобрать адрес на компоненты
     *
     * @return array{city?: string, street?: string, house?: string, full?: string}
     */
    private function parseAddress(string $address): array
    {
        if (empty($address)) {
            return ['full' => ''];
        }

        // Формат: "Область, Город, Улица, Дом"
        $parts = array_map('trim', explode(',', $address));

        $result = ['full' => $address];

        if (count($parts) >= 1) {
            // Последний значимый элемент — город или улица
            foreach (array_reverse($parts) as $part) {
                if (preg_match('/ул\.|улицы|улиц./i', $part)) {
                    $result['street'] = $part;
                } elseif (preg_match('/д\.|д[А-Яа-я]?\.|дом/i', $part)) {
                    $result['house'] = $part;
                } elseif (!preg_match('/обл\.|области|республика|край/i', $part)) {
                    $result['city'] = $part;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Сформировать описание объявления
     */
    private function buildDescription(string $title, array $masterData, string $categoryName): string
    {
        $parts = [$title];

        // Добавляем город если есть
        $location = $masterData['location'] ?? '';
        if (!empty($location)) {
            $parts[] = "Город: {$location}";
        }

        // Добавляем категорию
        if (!empty($categoryName)) {
            $parts[] = "Категория: {$categoryName}";
        }

        // Добавляем ссылку
        $url = $masterData['url'] ?? '';
        if (!empty($url)) {
            $parts[] = "Ссылка: {$url}";
        }

        return implode("\n", $parts);
    }

    /**
     * Получить информацию о последней генерации
     *
     * @return array{last_file?: string, last_count?: int, last_date?: string}
     */
    public function getLastGeneration(): array
    {
        $feedDir = $this->outputDir;
        if (!is_dir($feedDir)) {
            return [];
        }

        $files = glob($feedDir . '/avito_feed_*.tsv');
        if (empty($files)) {
            return [];
        }

        // Берём последний файл по дате
        usort($files, function ($a, $b) {
            return strcmp(basename($b), basename($a));
        });

        $lastFile = $files[0];
        $basename = basename($lastFile, '.tsv');

        // Извлекаем дату из имени файла
        if (preg_match('/avito_feed_(\d{4}-\d{2}-\d{2})/', $basename, $matches)) {
            $date = $matches[1];
        } else {
            $date = date('Y-m-d', filemtime($lastFile));
        }

        // Считаем количество строк (минус заголовок)
        $lineCount = count(file($lastFile)) - 1;

        return [
            'last_file' => $lastFile,
            'last_count' => $lineCount,
            'last_date' => $date,
        ];
    }
}
