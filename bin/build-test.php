<?php

declare(strict_types=1);

/**
 * Проверка сборки подборки без чат-интеграции — то же самое, что делает команда "кейсы"
 * (src/Bot/Commands/CasesCommand.php), только без VK Teams: найти по тегам и собрать pptx.
 *
 * Запуск: php bin/build-test.php "technology:1с, industry:ритейл"
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\TagTaxonomy;
use CasesBot\Presentation\PresentationBuilder;
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

if (!isset($argv[1])) {
    fwrite(STDERR, "Использование: php bin/build-test.php \"категория:тег[, категория:тег...]\"\n");
    exit(1);
}

$tags = TagTaxonomy::parseTagList($argv[1]);
if ($tags === []) {
    fwrite(STDERR, "Не разобрано ни одного тега из «{$argv[1]}» "
        . '(допустимые категории: ' . implode(', ', TagTaxonomy::VALID_CATEGORIES) . ")\n");
    exit(1);
}

$catalog = new CatalogRepository($config['catalog']['storage_path'], __DIR__ . '/../storage/catalog/schema.sql');
$presentations = new LocalPresentationsClient($config['presentations']['folder_path']);
$slideCloner = new SlideCloner($config['python']['bin'], $config['python']['slide_cloner']);
$builder = new PresentationBuilder(
    $slideCloner,
    $config['python']['bin'],
    $config['python']['slide_cloner'],
    $config['storage']['output']
);

$rows = $catalog->findByTags($tags, $config['max_slides_per_deck']);
if ($rows === []) {
    fwrite(STDOUT, "Кейсов по тегам «{$argv[1]}» не найдено.\n");
    exit(0);
}

$slides = array_map(
    static fn (array $row): array => [
        'source_path' => $presentations->getFilePath($row['source_file_id']),
        'slide_number' => $row['slide_number'],
    ],
    $rows
);

fwrite(STDOUT, 'Найдено кейсов: ' . count($rows) . "\n");
foreach ($rows as $row) {
    fwrite(STDOUT, "  #{$row['id']} слайд {$row['slide_number']} (совпадений: {$row['match_count']}): {$row['title']}\n");
}

$outputPath = $builder->build("Кейсы: {$argv[1]}", $slides);

fwrite(STDOUT, "\nСобрано: {$outputPath}\n");
