<?php

namespace App\Services;

/**
 * Источник идентификаторов объявлений из файла автозагрузки.
 */
interface AutoloadIdProvider
{
    /**
     * @param list<int|string> $avitoIds
     * @return list<array{avito_id: string, ad_id: ?string}>
     */
    public function getAdIdsByAvitoIds(array $avitoIds): array;
}
