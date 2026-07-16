<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

use CasesBot\Presentation\SlideTextExtractor;
use CasesBot\Storage\LocalPresentationsClient;

/**
 * Обходит презентации (временно — папка presentations/ в репозитории, см. LocalPresentationsClient;
 * интеграция с Google Drive отложена), через SlideTextExtractor предлагает теги,
 * сохраняет в каталог только после подтверждения эксперта (§5.1.8 ТЗ).
 */
class Indexer
{
    public function __construct(
        private LocalPresentationsClient $presentations,
        private SlideTextExtractor $slideTextExtractor,
        private TagTaxonomy $tagTaxonomy,
        private CatalogRepository $catalog,
    ) {
    }

    /**
     * Индексирует все презентации из источника. $prompt получает (имя файла, слайд, предложенные теги)
     * и возвращает ['tags' => ..., 'site_url' => ...] для сохранения, либо null, чтобы пропустить слайд —
     * так вызывающий код (CLI) решает, как именно спросить подтверждение у эксперта.
     *
     * @param callable(string, array{slide_number:int,title:string,text:string}, array<int,array{category:string,tag:string}>): (array{tags: array<int,array{category:string,tag:string}>, site_url: ?string}|null) $prompt
     */
    public function run(callable $prompt): void
    {
        foreach ($this->presentations->listPresentations() as $file) {
            $path = $this->presentations->getFilePath($file['id']);
            $slides = $this->slideTextExtractor->extract($path);

            foreach ($slides as $slide) {
                $suggested = $this->tagTaxonomy->suggestTags($slide['title'] . "\n" . $slide['text']);

                $answer = $prompt($file['name'], $slide, $suggested);
                if ($answer === null) {
                    continue;
                }

                $this->catalog->saveCase(new CaseItem(
                    id: null,
                    sourceFileId: $file['id'],
                    sourceFileName: $file['name'],
                    slideNumber: $slide['slide_number'],
                    title: $slide['title'] !== '' ? $slide['title'] : null,
                    tags: $answer['tags'],
                    addedAt: date(DATE_ATOM),
                    siteUrl: $answer['site_url'],
                ));
            }
        }
    }
}
