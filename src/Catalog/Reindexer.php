<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

use CasesBot\Api\Providers\ProviderFactory;
use CasesBot\Presentation\SlideTextExtractor;
use CasesBot\Storage\LocalPresentationsClient;

/**
 * Собирает граф объектов индексации из config (так его строят и bin/index.php, и public/upload.php)
 * и запускает автоматическую переиндексацию (Indexer::runAutomatic — без диалога по слайдам,
 * источник тегов — заметки докладчика, ТЗ §5.1.8). Единая точка нужна, чтобы переиндексация
 * из веб-загрузчика и из sync-шага Google Drive (bin/sync.php) вела себя одинаково.
 */
class Reindexer
{
    private function __construct(
        private Indexer $indexer,
    ) {
    }

    public static function fromConfig(array $config): self
    {
        $presentations = new LocalPresentationsClient($config['presentations']['folder_path'], '', $config['presentations']['public_name_overrides'] ?? []);
        $slideTextExtractor = new SlideTextExtractor(
            $config['python']['bin'],
            $config['python']['slide_text_extractor'],
            $config['case_marker'],
            $config['catalog']['images_path']
        );
        $tagTaxonomy = new TagTaxonomy($config['tags_taxonomy_path']);
        $catalog = new CatalogRepository(
            $config['catalog']['storage_path'],
            self::schemaPath($config)
        );

        $llmTagSuggester = null;
        $llmProviderName = $config['llm']['provider'];
        $llmProviderConfig = $config['llm'][$llmProviderName] ?? [];
        if (($llmProviderConfig['api_key'] ?? '') !== '' && ($llmProviderConfig['model'] ?? '') !== '') {
            $llmTagSuggester = new LlmTagSuggester(
                ProviderFactory::create($llmProviderName, $config['llm']),
                $tagTaxonomy
            );
        }

        return new self(new Indexer($presentations, $slideTextExtractor, $tagTaxonomy, $catalog, $llmTagSuggester));
    }

    /** schema.sql лежит рядом с базой; относительный catalog-путь из .env считается от корня репозитория. */
    private static function schemaPath(array $config): string
    {
        $storagePath = (string) $config['catalog']['storage_path'];
        $schemaPath = dirname($storagePath) . '/schema.sql';

        if (preg_match('#^(?:[A-Za-z]:[/\\\\]|/)#', $schemaPath) === 1) {
            return $schemaPath;
        }

        return __DIR__ . '/../../' . $schemaPath;
    }

    /**
     * Переиндексирует все презентации источника (clearAll + построение заново).
     *
     * @return array{indexed: array<int, array{file: string, slide: int, title: string, tags: string[]}>, llmErrors: string[]}
     */
    public function runAutomatic(): array
    {
        $llmErrors = [];
        $indexed = $this->indexer->runAutomatic(static function (string $error) use (&$llmErrors): void {
            $llmErrors[] = $error;
        });

        return ['indexed' => $indexed, 'llmErrors' => $llmErrors];
    }
}
