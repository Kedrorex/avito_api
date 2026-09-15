<?php

/**
 * Маршруты API
 *
 * Slim Framework routes
 */

use Slim\App;

return function (App $app) {
    // CLI запуск (полный пайплайн)
    $app->post('/run', \App\Controllers\AvitoController::class . ':run');

    // Получить активные объявления
    $app->get('/active', \App\Controllers\AvitoController::class . ':getActive');

    // Синхронизация с Avito API
    $app->post('/sync', \App\Controllers\AvitoController::class . ':sync');

    // Получить статистику
    $app->get('/stats', \App\Controllers\AvitoController::class . ':getStats');

    // Переопубликовать объявление
    $app->post('/republish/{id}', \App\Controllers\AvitoController::class . ':republish');

    // Подсчёт по статусам
    $app->get('/status-counts', \App\Controllers\AvitoController::class . ':countByStatus');

    // Детали объявления
    $app->get('/item/{id}', \App\Controllers\AvitoController::class . ':getItemDetail');

    // Кандидаты для републикации
    $app->post('/collect-candidates', \App\Controllers\AvitoController::class . ':collectCandidates');
    $app->get('/candidates', \App\Controllers\AvitoController::class . ':getCandidates');
    $app->delete('/candidates/{id}', \App\Controllers\AvitoController::class . ':removeCandidate');

    // Генерация фида для Avito AutoLoad
    $app->post('/feed/generate', \App\Controllers\AvitoController::class . ':generateFeed');
    $app->get('/feed/info', \App\Controllers\AvitoController::class . ':getFeedInfo');
};
