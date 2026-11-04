<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

use CasesBot\Presentation\SlideTextExtractor;
use CasesBot\Storage\LocalPresentationsClient;
use Throwable;

/**
 * Обходит презентации (LocalPresentationsClient; sync с Google Drive обновляет те же файлы)
 * и индексирует ВСЕ слайды каждой презентации — не только помеченные меткой #кейс#: разметка
 * «кейс/не кейс» переехала из заметок докладчика в веб-интерфейс (public/tags.php), по умолчанию
 * слайд считается кейсом, эксперт отмечает в интерфейсе только не-кейсы (обложка, о компании,
 * контакты). Метка #кейс# в заметках сохраняет смысл только при первом индексе презентаций,
 * размеченных по старому процессу: слайды без метки получают is_not_case = 1.
 *
 * Теги после переезда разметки в веб:
 *  - notes — хэштеги из заметок докладчика (#erp #производство#), переносятся для слайдов,
 *    которые эксперт ещё не размечал (tags_reviewed = 0); у размеченных теги не трогаются;
 *  - expert — решения эксперта в веб-интерфейсе, не трогаются никогда;
 *  - словарь (TagTaxonomy::suggestTags) и LLM (LlmTagSuggester) — больше не теги, а
 *    ПРЕДЛОЖЕНИЯ: пишутся в tag_suggestions и становятся тегами только после подтверждения
 *    эксперта в веб-интерфейсе.
 *
 * Переиндексация идемпотентна и не стирает ручное: слайды сопоставляются со строками
 * каталога по содержимому (SlideMatcher — переживают перестановка слайдов и правки текста),
 * контент обновляется, теги/метки/подтверждения остаются. Слайды, которых больше нет в
 * исходнике, удаляются. Атомарность: вся операция в одной транзакции SQLite (WAL) — при
 * сбое каталог откатывается к прежнему состоянию; параллельный запуск исключает
 * flock-лок (Reindexer).
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
     * Индексирует все презентации источника без ручного ввода — используется веб-загрузчиком
     * (public/upload.php), sync-шагом Google Drive (bin/sync.php) и CLI-проверкой (bin/index.php).
     *
     * @param ?callable(string): void $onLlmError Вызывается, если LLM недоступна/вернула ошибку.
     * @return array<int, array{file: string, slide: int, title: string, tags: string[], status: string, suggestions: int}>
     */
    public function runAutomatic(?callable $onLlmError = null): array
    {
        return $this->catalog->transactional(function () use ($onLlmError): array {
            $report = [];
            $fileIds = [];

            foreach ($this->presentations->listPresentations() as $file) {
                $fileIds[] = $file['id'];
                $report = array_merge($report, $this->indexPresentation($file, $onLlmError));
            }

            // Презентации, удалённые из источника целиком: строки каталога больше не актуальны.
            $this->catalog->pruneMissing($fileIds);

            return $report;
        });
    }

    /**
     * Индексирует одну презентацию: сопоставляет слайды со строками каталога, обновляет
     * контент, переносит notes-теги неразмеченных слайдов, кладёт предложения в очередь.
     *
     * @param array{id: string, name: string} $file
     * @param ?callable(string): void $onLlmError
     * @return array<int, array{file: string, slide: int, title: string, tags: string[], status: string, suggestions: int}>
     */
    private function indexPresentation(array $file, ?callable $onLlmError): array
    {
        $path = $this->presentations->getFilePath($file['id']);
        $slides = $this->slideTextExtractor->extract($path);

        $existingRows = $this->catalog->slideRowsForFile($file['id']);
        $rowsById = [];
        foreach ($existingRows as $row) {
            $rowsById[$row['id']] = $row;
        }

        $plan = (new SlideMatcher())->match($slides, $existingRows);

        // Презентация старого образца (размечена метками в заметках): слайды без метки —
        // не кейсы. Презентация без меток вообще — новая модель: все слайды кейсы,
        // эксперт отметит не-кейсы в веб-интерфейсе.
        $hasMarkers = false;
        foreach ($slides as $slide) {
            if ($slide['is_case']) {
                $hasMarkers = true;
                break;
            }
        }

        // Позиции существующих строк временно становятся отрицательными — иначе обновление
        // slide_number по цепочке переставленных слайдов нарушает UNIQUE (см. CatalogRepository).
        $this->catalog->stageSlidePositionsForReindex($file['id']);

        $report = [];

        foreach ($slides as $slide) {
            $caseItem = new CaseItem(
                id: null,
                sourceFileId: $file['id'],
                sourceFileName: $file['name'],
                slideNumber: $slide['slide_number'],
                title: $slide['title'] !== '' ? $slide['title'] : null,
                content: $slide['text'] !== '' ? $slide['text'] : null,
                tags: [],
                images: $slide['images'] ?? [],
                addedAt: date(DATE_ATOM),
                siteUrl: null,
                reviewerComments: ($slide['comments'] ?? '') !== '' ? $slide['comments'] : null,
                contentHash: SlideMatcher::contentHash($slide['title'], $slide['text']),
            );

            $notesTags = $this->dedupeTags($this->notesTags($slide['notes']));

            $existingId = $plan['matched'][$slide['slide_number']] ?? null;

            if ($existingId !== null) {
                $row = $rowsById[$existingId];
                $changed = ($row['content_hash'] ?? null) !== $caseItem->contentHash;

                $this->catalog->updateCaseContent($existingId, $caseItem);

                $status = (int) $row['slide_number'] !== $slide['slide_number']
                    ? ($changed ? 'updated' : 'moved')
                    : ($changed ? 'updated' : 'unchanged');

                // Неразмеченный слайд: заметки остаются источником тегов (эксперт мог их
                // поправить в PowerPoint). Размеченный (tags_reviewed = 1) — не трогаем.
                if (!$row['tags_reviewed']) {
                    $this->catalog->syncNotesTags($existingId, $notesTags);
                }

                // Предложения обновляются только у изменившихся слайдов: без изменений —
                // очередь эксперта не трогается, лишних вызовов LLM нет.
                $suggestions = 0;
                if ($changed) {
                    $suggestions = $this->queueSuggestions($existingId, $slide, $onLlmError);
                }

                $report[] = $this->reportRow($file, $slide, $existingId, $status, $suggestions);

                continue;
            }

            $isNotCase = $hasMarkers && !$slide['is_case'];
            $caseId = $this->catalog->insertCase($caseItem, $isNotCase, $notesTags);

            $suggestions = $this->queueSuggestions($caseId, $slide, $onLlmError);

            $report[] = $this->reportRow($file, $slide, $caseId, 'new', $suggestions);
        }

        // Строки, которым не нашлось пары (слайд удалили), и их картинки.
        $this->catalog->pruneSlidesOfFile(
            $file['id'],
            array_map(static fn (array $slide): int => (int) $slide['slide_number'], $slides)
        );

        return $report;
    }

    /**
     * Кладёт словарные и LLM-предложения тегов в очередь tag_suggestions. Возвращает
     * число новых pending-предложений. Ошибка LLM не прерывает индексацию.
     *
     * @param array{title: string, text: string, comments?: string} $slide
     * @param ?callable(string): void $onLlmError
     */
    private function queueSuggestions(int $caseId, array $slide, ?callable $onLlmError): int
    {
        $dictTags = $this->tagTaxonomy->normalizeTags(
            $this->tagTaxonomy->suggestTags($slide['title'] . "\n" . $slide['text'] . "\n" . ($slide['comments'] ?? ''))
        );

        $llmTags = [];
        if ($this->llmTagSuggester !== null) {
            try {
                $llmTags = $this->llmTagSuggester->suggest(
                    $slide['title'],
                    $slide['text'],
                    $slide['comments'] ?? ''
                );
                $llmTags = $this->tagTaxonomy->normalizeTags($llmTags);
            } catch (Throwable $e) {
                if ($onLlmError !== null) {
                    $onLlmError($e->getMessage());
                }
            }
        }

        return $this->catalog->refreshSuggestions($caseId, $dictTags, $llmTags);
    }

    /** @param array{id: string, name: string} $file */
    private function reportRow(array $file, array $slide, int $caseId, string $status, int $suggestions): array
    {
        return [
            'file' => $file['name'],
            'slide' => $slide['slide_number'],
            'title' => $slide['title'],
            'tags' => $this->catalog->tagsForCase($caseId),
            'status' => $status,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Теги, которые эксперт перечислил в заметках докладчика как хэштеги (например
     * "#erp #производство#") — тегом считается только текст строго между двумя соседними
     * символами #; текст до первого # и после последнего (в т.ч. метка кейса #кейс#,
     * см. SlideTextExtractor::$caseMarker, и любые сопроводительные заметки) в теги не попадает.
     *
     * @return string[]
     */
    private function notesTags(string $notes): array
    {
        $parts = explode('#', $notes);
        if (count($parts) < 3) {
            return [];
        }

        $tags = [];
        foreach (array_slice($parts, 1, -1) as $part) {
            $tag = trim($part);
            if ($tag !== '' && mb_strtolower($tag) !== 'кейс') {
                $tags[] = $tag;
            }
        }

        return $tags;
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
