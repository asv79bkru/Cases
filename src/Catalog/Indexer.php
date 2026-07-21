<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

use CasesBot\Presentation\SlideTextExtractor;
use CasesBot\Storage\LocalPresentationsClient;
use Throwable;

/**
 * Обходит презентации (временно — папка presentations/ рядом с приложением, см. LocalPresentationsClient;
 * интеграция с Google Drive отложена). Индексируются только слайды, помеченные экспертом как кейс —
 * меткой в заметках докладчика (см. SlideTextExtractor::$caseMarker), невидимой на самом слайде и
 * в показе; остальное (обложка, о компании, контакты) пропускается автоматически, без вопросов.
 *
 * Предложенные теги собираются из трёх источников: (1) теги, которые эксперт сам перечислил
 * в заметках после метки кейса — им доверяем больше всего; (2) LLM по содержимому слайда
 * (LlmTagSuggester); (3) простое совпадение по словарю (TagTaxonomy::suggestTags). Сохраняется
 * в каталог только после подтверждения/правки экспертом (§5.1.8 ТЗ) — LLM лишь дополняет подсказку,
 * не решает за эксперта.
 */
class Indexer
{
    public function __construct(
        private LocalPresentationsClient $presentations,
        private SlideTextExtractor $slideTextExtractor,
        private TagTaxonomy $tagTaxonomy,
        private CatalogRepository $catalog,
        private ?LlmTagSuggester $llmTagSuggester = null,
    ) {
    }

    /**
     * Индексирует все презентации из источника. $prompt получает (имя файла, слайд, предложенные теги)
     * и возвращает ['tags' => ..., 'site_url' => ...] для сохранения, либо null, чтобы пропустить слайд —
     * так вызывающий код (CLI) решает, как именно спросить подтверждение у эксперта.
     *
     * @param callable(string, array{slide_number:int,title:string,text:string}, string[]): (array{tags: string[], site_url: ?string}|null) $prompt
     * @param ?callable(string): void $onLlmError Вызывается, если LLM недоступна/вернула ошибку — индексация продолжается без неё.
     */
    public function run(callable $prompt, ?callable $onLlmError = null): void
    {
        foreach ($this->presentations->listPresentations() as $file) {
            $path = $this->presentations->getFilePath($file['id']);
            $slides = $this->slideTextExtractor->extract($path);

            foreach ($slides as $slide) {
                if (!$slide['is_case']) {
                    continue;
                }

                $suggested = $this->collectSuggestedTags($slide, $onLlmError);

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
                    content: $slide['text'] !== '' ? $slide['text'] : null,
                    tags: $answer['tags'],
                    images: $slide['images'] ?? [],
                    addedAt: date(DATE_ATOM),
                    siteUrl: $answer['site_url'],
                ));
            }
        }
    }

    /**
     * @param array{title:string, text:string, notes:string} $slide
     * @param ?callable(string): void $onLlmError
     * @return string[]
     */
    private function collectSuggestedTags(array $slide, ?callable $onLlmError): array
    {
        $tags = $this->tagTaxonomy->suggestTags($slide['title'] . "\n" . $slide['text']);
        $tags = array_merge($tags, $this->notesTags($slide['notes']));

        if ($this->llmTagSuggester !== null) {
            try {
                $tags = array_merge(
                    $tags,
                    $this->llmTagSuggester->suggest($slide['title'], $slide['text'])
                );
            } catch (Throwable $e) {
                if ($onLlmError !== null) {
                    $onLlmError($e->getMessage());
                }
            }
        }

        return $this->dedupeTags($tags);
    }

    /** Теги, которые эксперт перечислил в заметках после метки кейса (см. SlideTextExtractor::$caseMarker). */
    private function notesTags(string $notes): array
    {
        $withoutMarker = trim((string) preg_replace('/#[^\s#]+#/u', '', $notes));

        return $withoutMarker !== '' ? TagTaxonomy::parseTagList($withoutMarker) : [];
    }

    /**
     * @param string[] $tags
     * @return string[]
     */
    private function dedupeTags(array $tags): array
    {
        $unique = [];
        foreach ($tags as $tag) {
            $unique[mb_strtolower($tag)] = $tag;
        }

        return array_values($unique);
    }
}
