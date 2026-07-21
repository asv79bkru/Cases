<?php

declare(strict_types=1);

/**
 * Автономная проверка CaseDeckBuilder (все слайды без метки #кейс# + переданные номера
 * кейсов, найденных по запросу) — без чат-интеграции, без запуска бота.
 *
 * Запуск: php bin/deck-test.php "Экспертиза направления бизнес автоматизации.pptx" "7,9"
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Presentation\CaseDeckBuilder;
use CasesBot\Presentation\SlideCloner;
use CasesBot\Presentation\SlideTextExtractor;
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

$presentations = new LocalPresentationsClient($config['presentations']['folder_path']);
$builder = new CaseDeckBuilder(
    new SlideTextExtractor($config['python']['bin'], $config['python']['slide_text_extractor'], $config['case_marker']),
    new SlideCloner($config['python']['bin'], $config['python']['slide_cloner']),
    $presentations,
    $config['storage']['output'],
);

$pptxPath = $builder->build($sourceFileId, $slideNumbers);

fwrite(STDOUT, "Собрано: {$pptxPath}\n");
