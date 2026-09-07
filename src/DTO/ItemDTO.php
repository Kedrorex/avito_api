<?php

namespace App\DTO;

/**
 * Данные об объявлении с Avito
 */
class ItemDTO
{
    public function __construct(
        public readonly int    $id,
        public readonly string $title,
        public readonly int    $price,
        public readonly string $status,
        public readonly ?string $url = null,
        public readonly ?string $address = null,
        public readonly array  $category = [],
        public readonly array  $master_data = [],
    ) {
    }

    /**
     * Создать из массива API-ответа
     */
    public static function fromArray(array $data): self
    {
        $category = $data['category'] ?? [];
        if (is_array($category) && isset($category['name'])) {
            $catName = $category['name'];
        } else {
            $catName = (string) ($category['name'] ?? '');
        }

        $address = $data['address'] ?? '';
        $city = '';
        if ($address && str_contains($address, ',')) {
            $parts = explode(',', $address);
            $city = trim(end($parts));
        }

        $categoryName = $catName ?: '';
        $logicalKey = $categoryName . '_' . ($city ?: 'unknown');

        return new self(
            id: (int) ($data['id'] ?? 0),
            title: (string) ($data['title'] ?? ''),
            price: (int) ($data['price'] ?? 0),
            status: (string) ($data['status'] ?? 'active'),
            url: $data['url'] ?? null,
            address: $address,
            category: $data['category'] ?? [],
            master_data: [
                'title' => $data['title'] ?? '',
                'price' => $data['price'] ?? 0,
                'address' => $address,
                'category' => $categoryName,
                'url' => $data['url'] ?? '',
                'logical_key' => $logicalKey,
            ],
        );
    }
}
