<?php
/**
 * Миграция: заполняет новые колонки physical_ads из master_data
 *
 * Запуск: php scripts/migrate_feed_columns.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../env_helper.php';

$pdo = new PDO(
    'sqlite:' . __DIR__ . '/../data/avito.db',
    null,
    null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "=== Миграция колонок фида из master_data ===\n\n";

// Получаем все active объявления
$ads = $pdo->query("SELECT id, master_data FROM physical_ads WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$skipped = 0;
$errors = 0;

foreach ($ads as $ad) {
    $masterData = json_decode($ad['master_data'], true);
    if ($masterData === null) {
        $skipped++;
        continue;
    }

    $fields = [];
    $params = [':id' => (int) $ad['id']];

    // title
    if (isset($masterData['title']) && $masterData['title'] !== '') {
        $fields[] = "title = :title";
        $params[':title'] = $masterData['title'];
    }

    // price
    if (isset($masterData['price']) && is_numeric($masterData['price'])) {
        $fields[] = "price = :price";
        $params[':price'] = (int) $masterData['price'];
    }

    // location
    if (isset($masterData['location']) && $masterData['location'] !== '') {
        $fields[] = "location = :location";
        $params[':location'] = $masterData['location'];
    }

    // phone
    if (isset($masterData['phone']) && $masterData['phone'] !== '') {
        $fields[] = "phone = :phone";
        $params[':phone'] = $masterData['phone'];
    }

    // contact_method
    if (isset($masterData['contact_method']) && $masterData['contact_method'] !== '') {
        $fields[] = "contact_method = :contact_method";
        $params[':contact_method'] = $masterData['contact_method'];
    }

    // brand
    if (isset($masterData['brand']) && $masterData['brand'] !== '') {
        $fields[] = "brand = :brand";
        $params[':brand'] = $masterData['brand'];
    }

    // oem_number
    if (isset($masterData['oem_number']) && $masterData['oem_number'] !== '') {
        $fields[] = "oem_number = :oem_number";
        $params[':oem_number'] = $masterData['oem_number'];
    }

    // category_params
    if (isset($masterData['category_params']) && is_array($masterData['category_params']) && $masterData['category_params'] !== []) {
        $fields[] = "category_params = :category_params";
        $params[':category_params'] = json_encode($masterData['category_params'], JSON_UNESCAPED_UNICODE);
    }

    // description — извлекаем из _full_api.description
    if (isset($masterData['_full_api']['description']) && $masterData['_full_api']['description'] !== '') {
        $fields[] = "description = :description";
        $params[':description'] = strip_tags($masterData['_full_api']['description']);
    }

    // images — извлекаем из _full_api.images
    if (isset($masterData['_full_api']['images']) && is_array($masterData['_full_api']['images'])) {
        $urls = [];
        foreach ($masterData['_full_api']['images'] as $img) {
            if (is_array($img)) {
                $url = $img['url'] ?? $img['thumb_url'] ?? '';
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }
        if ($urls !== []) {
            $fields[] = "images = :images";
            $params[':images'] = json_encode($urls, JSON_UNESCAPED_UNICODE);
        }
    }

    if ($fields !== []) {
        $sql = "UPDATE physical_ads SET " . implode(', ', $fields) . " WHERE id = :id";
        try {
            $pdo->prepare($sql)->execute($params);
            $updated++;
        } catch (\PDOException $e) {
            $errors++;
        }
    } else {
        $skipped++;
    }
}

echo "  Обновлено: {$updated}\n";
echo "  Пропущено (нет данных): {$skipped}\n";
echo "  Ошибки: {$errors}\n";

// Проверка — сколько записей теперь имеют данные
echo PHP_EOL . "=== Проверка заполненности ===\n";
$total = $pdo->query("SELECT COUNT(*) FROM physical_ads WHERE status = 'active'")->fetchColumn();
$withPhone = $pdo->query("SELECT COUNT(*) FROM physical_ads WHERE status = 'active' AND phone != ''")->fetchColumn();
$withTitle = $pdo->query("SELECT COUNT(*) FROM physical_ads WHERE status = 'active' AND title != ''")->fetchColumn();
$withPrice = $pdo->query("SELECT COUNT(*) FROM physical_ads WHERE status = 'active' AND price > 0")->fetchColumn();
$withLocation = $pdo->query("SELECT COUNT(*) FROM physical_ads WHERE status = 'active' AND location != ''")->fetchColumn();
$withDesc = $pdo->query("SELECT COUNT(*) FROM physical_ads WHERE status = 'active' AND description != ''")->fetchColumn();
$withImages = $pdo->query("SELECT COUNT(*) FROM physical_ads WHERE status = 'active' AND images != ''")->fetchColumn();

echo "  Всего active: {$total}\n";
echo "  С телефоном: {$withPhone}\n";
echo "  С названием: {$withTitle}\n";
echo "  С ценой: {$withPrice}\n";
echo "  С адресом: {$withLocation}\n";
echo "  С описанием: {$withDesc}\n";
echo "  С фото: {$withImages}\n";
