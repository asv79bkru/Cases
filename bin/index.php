<?php

declare(strict_types=1);

/**
 * CLI-инструмент индексации — тот же проход, что делает веб-загрузчик (public/upload.php)
 * и sync-шаг Google Drive: все слайды всех презентаций индексируются автоматически, без
 * диалога по слайдам. Ручная курация тегов и меток «не кейс» теперь делается в веб-интерфейсе
 * разметки (public/tags.php, порт :8081) — там же подтверждаются/отклоняются предложения
 * словаря и LLM. Сценарий прежнего интерактивного тегирования в заметках докладчика тоже
 * работает: хэштеги заметок переносятся как теги, пока слайд не размечен экспертом.
 *
 * Запуск: php bin/index.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\Reindexer;

$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (getenv($key) === false) {
            putenv($key . '=' . trim($value));
        }
    }
}

$config = require __DIR__ . '/../config/config.php';

$report = Reindexer::fromConfig($config)->runAutomatic();

$statusLabel = [
    'new' => 'новый',
    'moved' => 'переставлен',
    'updated' => 'обновлён',
    'unchanged' => 'без изменений',
];

fwrite(STDOUT, "Индексация завершена: обработано " . count($report['indexed']) . " слайд(ов).\n");
foreach ($report['indexed'] as $row) {
    $suggestions = $row['suggestions'] > 0 ? ", предложений: {$row['suggestions']}" : '';
    printf(
        "%s, слайд %d [%s]%s: %s [теги: %s]\n",
        $row['file'],
        $row['slide'],
        $statusLabel[$row['status']] ?? $row['status'],
        $suggestions,
        $row['title'] !== '' ? $row['title'] : '(без заголовка)',
        implode(', ', $row['tags']) !== '' ? implode(', ', $row['tags']) : '—'
    );
}

if ($report['llmErrors'] !== []) {
    fwrite(STDOUT, "\nLLM не смогла предложить теги для некоторых слайдов:\n" . implode("\n", array_unique($report['llmErrors'])) . "\n");
}

fwrite(STDOUT, "\nРазметка тегов и меток «не кейс» — в веб-интерфейсе (public/tags.php, порт :8081).\n");
fwrite(STDOUT, "Итого в каталоге:\n");
foreach ((new CatalogRepository(
    $config['catalog']['storage_path'],
    __DIR__ . '/../storage/catalog/schema.sql',
    $config['catalog']['images_path']
))->all() as $row) {
    $imagesCount = $row['images'] !== null ? count(explode(', ', $row['images'])) : 0;
    printf(
        "#%d %s (слайд %d)%s: %s [%s], картинок: %d\n",
        $row['id'],
        $row['source_file_name'],
        $row['slide_number'],
        $row['is_not_case'] ? ' [не кейс]' : '',
        $row['title'] ?? '(без заголовка)',
        $row['tags'] ?? '',
        $imagesCount
    );
}
