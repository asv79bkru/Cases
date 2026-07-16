<?php

declare(strict_types=1);

/**
 * CLI-инструмент индексации (§5.1.8, Этап 1 из §9 ТЗ).
 * Обходит презентации, предлагает теги через SlideTextExtractor, сохраняет в CatalogRepository
 * только после подтверждения эксперта: по каждому слайду — принять/поправить теги/пропустить,
 * плюс необязательная ссылка на сайт, один проход.
 *
 * Запуск: php bin/index.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Api\Providers\ProviderFactory;
use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\Indexer;
use CasesBot\Catalog\LlmTagSuggester;
use CasesBot\Catalog\TagTaxonomy;
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

$presentations = new LocalPresentationsClient($config['presentations']['folder_path']);
$slideTextExtractor = new SlideTextExtractor(
    $config['python']['bin'],
    $config['python']['slide_text_extractor'],
    $config['case_marker'],
    $config['catalog']['images_path']
);
$tagTaxonomy = new TagTaxonomy($config['tags_taxonomy_path']);
$catalog = new CatalogRepository($config['catalog']['storage_path'], __DIR__ . '/../storage/catalog/schema.sql');

$llmTagSuggester = null;
$llmProviderName = $config['llm']['provider'];
$llmProviderConfig = $config['llm'][$llmProviderName] ?? [];
if (($llmProviderConfig['api_key'] ?? '') !== '' && ($llmProviderConfig['model'] ?? '') !== '') {
    $llmTagSuggester = new LlmTagSuggester(
        ProviderFactory::create($llmProviderName, $config['llm']),
        $tagTaxonomy
    );
} else {
    fwrite(STDOUT, "LLM-провайдер «{$llmProviderName}» не настроен (нет api_key/model в .env) — "
        . "теги дополняются только словарём и заметками.\n");
}

$indexer = new Indexer($presentations, $slideTextExtractor, $tagTaxonomy, $catalog, $llmTagSuggester);

$indexer->run(static function (string $fileName, array $slide, array $suggested): ?array {
    fwrite(STDOUT, "\n=== {$fileName}, слайд {$slide['slide_number']} ===\n");
    if ($slide['title'] !== '') {
        fwrite(STDOUT, "Заголовок: {$slide['title']}\n");
    }
    $preview = mb_substr(str_replace("\n", ' / ', $slide['text']), 0, 200);
    fwrite(STDOUT, "Текст: {$preview}\n");

    $suggestedText = implode(', ', array_map(
        static fn (array $t): string => "{$t['category']}:{$t['tag']}",
        $suggested
    ));
    fwrite(STDOUT, 'Предложенные теги: ' . ($suggestedText !== '' ? $suggestedText : '(нет)') . "\n");

    fwrite(STDOUT, "Теги [Enter — принять предложенные, skip — пропустить слайд, "
        . "или свои через запятую вида категория:тег]: ");
    $line = trim((string) fgets(STDIN));

    if (strtolower($line) === 'skip') {
        return null;
    }

    $tags = $suggested;
    if ($line !== '') {
        $tags = [];
        foreach (explode(',', $line) as $pair) {
            [$category, $tag] = array_pad(explode(':', trim($pair), 2), 2, null);
            $category = $category !== null ? trim($category) : null;
            $tag = $tag !== null ? trim($tag) : null;

            if ($category === null || $tag === null || $tag === '') {
                continue;
            }
            if (!in_array($category, TagTaxonomy::VALID_CATEGORIES, true)) {
                fwrite(
                    STDOUT,
                    "Пропущено «{$category}:{$tag}»: неизвестная категория "
                        . '(допустимо: ' . implode(', ', TagTaxonomy::VALID_CATEGORIES) . ")\n"
                );
                continue;
            }

            $tags[] = ['category' => $category, 'tag' => $tag];
        }
    }

    fwrite(STDOUT, 'Ссылка на сайт [необязательно, Enter — пропустить]: ');
    $siteUrl = trim((string) fgets(STDIN));

    return ['tags' => $tags, 'site_url' => $siteUrl !== '' ? $siteUrl : null];
}, static function (string $error): void {
    fwrite(STDOUT, "LLM не смогла дополнить теги: {$error}\n");
});

fwrite(STDOUT, "\nИндексация завершена. Итого в каталоге:\n");
foreach ($catalog->all() as $row) {
    $imagesCount = $row['images'] !== null ? count(explode(', ', $row['images'])) : 0;
    printf(
        "#%d %s (слайд %d): %s [%s]%s, картинок: %d\n",
        $row['id'],
        $row['source_file_name'],
        $row['slide_number'],
        $row['title'] ?? '(без заголовка)',
        $row['tags'] ?? '',
        $row['site_url'] !== null ? " -> {$row['site_url']}" : '',
        $imagesCount
    );
}
