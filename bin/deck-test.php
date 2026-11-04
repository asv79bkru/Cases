<?php

declare(strict_types=1);

/**
 * Автономная проверка CaseDeckBuilder (все слайды «не кейс» из каталога + переданные номера
 * кейсов, найденных по запросу) — без чат-интеграции, без запуска бота.
 *
 * Запуск: php bin/deck-test.php "Экспертиза направления бизнес автоматизации.pptx" "7,9"
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Catalog\CatalogRepository;
use CasesBot\Presentation\CaseDeckBuilder;
use CasesBot\Presentation\SlideCloner;
use CasesBot\Storage\LocalPresentationsClient;

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

if (!isset($argv[1], $argv[2])) {
    fwrite(STDERR, "Использование: php bin/deck-test.php <source_file_id> <номера_слайдов_через_запятую>\n");
    exit(1);
}

$sourceFileId = $argv[1];
$slideNumbers = array_map('intval', explode(',', $argv[2]));

$presentations = new LocalPresentationsClient($config['presentations']['folder_path'], '', $config['presentations']['public_name_overrides'] ?? []);
$builder = new CaseDeckBuilder(
    new SlideCloner($config['python']['bin'], $config['python']['slide_cloner']),
    $presentations,
    $config['storage']['output'],
);

// Список «не-кейсов» теперь живёт в каталоге (веб-интерфейс разметки), а не в заметках исходника.
$catalog = new CatalogRepository(
    $config['catalog']['storage_path'],
    __DIR__ . '/../storage/catalog/schema.sql',
    $config['catalog']['images_path']
);
$notCaseSlides = $catalog->notCaseSlideNumbers($sourceFileId);

$pptxPath = $builder->build($sourceFileId, $slideNumbers, $notCaseSlides);

fwrite(STDOUT, "Собрано: {$pptxPath}\n");
