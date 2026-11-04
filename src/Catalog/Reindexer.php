<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

use CasesBot\Api\Providers\ProviderFactory;
use CasesBot\Presentation\SlideTextExtractor;
use CasesBot\Storage\LocalPresentationsClient;

/**
 * Собирает граф объектов индексации из config (так его строят и bin/index.php, и public/upload.php)
 * и запускает автоматическую переиндексацию (Indexer::runAutomatic). Единая точка нужна, чтобы
 * переиндексация из веб-загрузчика и из sync-шага Google Drive (bin/sync.php) вела себя одинаково.
 * Теги после переезда разметки в веб-интерфейс (public/tags.php) при переиндексации не
 * перетираются: обновляется только контент слайдов и очередь предложений (см. Indexer).
 */
class Reindexer
{
    private function __construct(
        private Indexer $indexer,
        private string $lockFile,
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
        $tagTaxonomy = new TagTaxonomy($config['tags_taxonomy_path'], $config['tag_relations_path'] ?? null);
        $catalog = new CatalogRepository(
            $config['catalog']['storage_path'],
            self::schemaPath($config),
            $config['catalog']['images_path']
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

        // Лок рядом с базой (bind-mount storage/catalog) — общий для всех процессов: sync при
        // старте контейнера и кнопка «Переиндексация» на upload.php не должны идти внахлёст.
        return new self(
            new Indexer($presentations, $slideTextExtractor, $tagTaxonomy, $catalog, $llmTagSuggester),
            dirname(self::schemaPath($config)) . '/reindex.lock'
        );
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
     * Переиндексирует все презентации источника (обновление существующих слайдов + вставка
     * новых + удаление исчезнувших; атомарно — в одной транзакции, см. Indexer::runAutomatic).
     * Повторный запуск, пока предыдущий не закончился, отклоняется: flock на reindex.lock,
     * общий для sync и веб-загрузчика.
     *
     * @return array{indexed: array<int, array{file: string, slide: int, title: string, tags: string[], status: string, suggestions: int}>, llmErrors: string[]}
     */
    public function runAutomatic(): array
    {
        $lock = fopen($this->lockFile, 'c');
        if ($lock === false) {
            throw new RuntimeException("Не удалось открыть лок-файл {$this->lockFile}.");
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            throw new RuntimeException('Переиндексация уже выполняется — дождитесь её завершения.');
        }

        try {
            $llmErrors = [];
            $indexed = $this->indexer->runAutomatic(static function (string $error) use (&$llmErrors): void {
                $llmErrors[] = $error;
            });

            return ['indexed' => $indexed, 'llmErrors' => $llmErrors];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
