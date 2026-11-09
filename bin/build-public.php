<?php

declare(strict_types=1);

/**
 * CLI: пересобирает «парадные» публичные копии всех презентаций из presentations/ —
 * те же слайды в исходном порядке через SlideCloner::cloneAll(), но без заметок докладчика
 * (#кейс#, теги эксперта) и без комментариев рецензентов (PublicCopyBuilder).
 * docker/entrypoint.sh отдаёт на :8080 именно этот каталог (presentations-public/), а сырые
 * исходники с пометками наружу не выходят — они нужны только индексации.
 *
 * Запуск: php bin/build-public.php
 * Запускается и вручную после массовой замены исходников, и позже — из sync-шага Google Drive.
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Presentation\PublicCopyBuilder;
use CasesBot\Presentation\SlideCloner;

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

$slideCloner = new SlideCloner($config['python']['bin'], $config['python']['slide_cloner']);
$builder = new PublicCopyBuilder(
    $slideCloner,
    $config['presentations']['folder_path'],
    $config['presentations']['public_folder_path']
);

$report = $builder->rebuildAll(
    $config['presentations']['public_name_overrides'] ?? [],
    array_values($config['presentations']['main_name_overrides'] ?? [])
);

fwrite(STDOUT, "Публичные копии пересобраны: " . count($report['built']) . " шт.\n");
foreach ($report['built'] as $name) {
    fwrite(STDOUT, "  + {$name}\n");
}
if ($report['removed'] !== []) {
    fwrite(STDOUT, "Удалены копии без исходника: " . count($report['removed']) . " шт.\n");
    foreach ($report['removed'] as $name) {
        fwrite(STDOUT, "  - {$name}\n");
    }
}
if ($report['errors'] !== []) {
    fwrite(STDERR, "Ошибки пересборки:\n" . implode("\n", $report['errors']) . "\n");
    exit(1);
}
