<?php

namespace App\DTO;

/**
 * Объект статистики по объявлению за один день
 */
class StatsDTO
{
    public function __construct(
        public readonly string  $date,
        public readonly int     $views = 0,
        public readonly int     $uniqViews = 0,
        public readonly int     $contacts = 0,
        public readonly int     $uniqContacts = 0,
        public readonly int     $favorites = 0,
        public readonly int     $uniqFavorites = 0,
    ) {
    }

    /**
     * Создать из массива API-ответа
     */
    public static function fromArray(array $data): self
    {
        return new self(
            date: $data['date'] ?? '',
            views: (int) ($data['views'] ?? $data['uniqViews'] ?? 0),
            uniqViews: (int) ($data['uniqViews'] ?? 0),
            contacts: (int) ($data['contacts'] ?? $data['uniqContacts'] ?? 0),
            uniqContacts: (int) ($data['uniqContacts'] ?? 0),
            favorites: (int) ($data['favorites'] ?? 0),
            uniqFavorites: (int) ($data['uniqFavorites'] ?? 0),
        );
    }
}
