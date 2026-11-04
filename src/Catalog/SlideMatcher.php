<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

/**
 * Сопоставляет слайды из свежепрочитанной презентации со строками каталога при переиндексации.
 *
 * Ключ (source_file_id, slide_number) — позиционный, поэтому перестановка слайдов в
 * Google Slides/PowerPoint без сопоставления по содержимому привязала бы старые теги
 * к чужим слайдам. Поэтому сопоставление трёхступенчатое (аналог rename detection в git):
 *
 *  1. точное совпадение content_hash (нормализованный текст слайда) — слайд не изменён
 *     или просто переставлен;
 *  2. точное совпадение нормализованного заголовка — слайд редактировали, но заголовок уцелел;
 *  3. похожесть полного текста (similar_text >= порога) — заметно отредактированный слайд.
 *
 * Сопоставление взаимно однозначное: одну строку каталога может занять только один слайд.
 * Слайды, которым не нашлось пары, считаются новыми; строки каталога без пары — удалёнными.
 */
class SlideMatcher
{
    /** Минимальная похожесть полного текста, при которой слайд считается тем же кейсом. */
    private const SIMILARITY_THRESHOLD = 80.0;

    /**
     * @param array<int, array{slide_number: int, title: string, text: string, is_case: bool, ...}> $slides Слайды из SlideTextExtractor.
     * @param array<int, array{id: int, slide_number: int, title: ?string, content: ?string, content_hash: ?string}> $existingRows Строки каталога этой презентации.
     * @return array{matched: array<int, int>, new: array<int, int>, removed: array<int, int>}
     *         matched: slide_number => id строки каталога; new: slide_number новых слайдов;
     *         removed: id строк каталога, которым не нашлось пары.
     */
    public function match(array $slides, array $existingRows): array
    {
        $byHash = [];
        foreach ($existingRows as $row) {
            if ($row['content_hash'] !== null && $row['content_hash'] !== '') {
                $byHash[$row['content_hash']][] = $row;
            }
        }

        $consumedRowIds = [];
        $matched = [];
        $new = [];

        foreach ($slides as $slide) {
            $hash = self::contentHash($slide['title'], $slide['text']);

            // 1. Точный текст — слайд не изменён (возможно, переставлен).
            $row = $this->takeCandidate($byHash[$hash] ?? [], $consumedRowIds);
            if ($row !== null) {
                $matched[$slide['slide_number']] = $row['id'];
                continue;
            }

            // 2–3. Редактированный слайд: точный заголовок, иначе — похожесть текста.
            $row = $this->findSimilar($slide, $existingRows, $consumedRowIds);
            if ($row !== null) {
                $matched[$slide['slide_number']] = $row['id'];
                continue;
            }

            $new[] = $slide['slide_number'];
        }

        $removed = [];
        foreach ($existingRows as $row) {
            if (!isset($consumedRowIds[$row['id']])) {
                $removed[] = (int) $row['id'];
            }
        }

        return ['matched' => $matched, 'new' => $new, 'removed' => $removed];
    }

    /** Хэш содержимого слайда — по нему слайд узнаётся при переиндексации (нормализация — нижний регистр, схлопнутые пробелы). */
    public static function contentHash(?string $title, ?string $text): string
    {
        $normalized = mb_strtolower(trim(($title ?? '') . "\n" . ($text ?? '')));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';

        return sha1($normalized);
    }

    /**
     * Первый не занятый кандидат из списка (строки с одинаковым хэшем при слайдах-дублях).
     *
     * @param array<int, array> $candidates
     * @param array<int, bool> $consumedRowIds
     */
    private function takeCandidate(array $candidates, array &$consumedRowIds): ?array
    {
        foreach ($candidates as $row) {
            if (!isset($consumedRowIds[$row['id']])) {
                $consumedRowIds[$row['id']] = true;

                return $row;
            }
        }

        return null;
    }

    /**
     * Ищет среди не занятых строк каталога ту же пару для отредактированного слайда:
     * точный заголовок сильнее, иначе — похожесть полного текста (similar_text).
     *
     * @param array<int, array> $existingRows
     * @param array<int, bool> $consumedRowIds
     */
    private function findSimilar(array $slide, array $existingRows, array $consumedRowIds): ?array
    {
        $title = mb_strtolower(trim($slide['title']));
        $fullText = $slide['title'] . "\n" . $slide['text'];

        $best = null;
        $bestScore = 0.0;

        foreach ($existingRows as $row) {
            if (isset($consumedRowIds[$row['id']])) {
                continue;
            }

            $rowTitle = mb_strtolower(trim((string) $row['title']));
            $score = $title !== '' && $rowTitle === $title
                ? 100.0
                : $this->similarity($fullText, ($row['title'] ?? '') . "\n" . ($row['content'] ?? ''));

            if ($score > $bestScore) {
                $best = $row;
                $bestScore = $score;
            }
        }

        if ($best !== null && $bestScore >= self::SIMILARITY_THRESHOLD) {
            $consumedRowIds[$best['id']] = true;

            return $best;
        }

        return null;
    }

    private function similarity(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 100.0;
        }

        $percent = 0.0;
        similar_text($a, $b, $percent);

        return (float) $percent;
    }
}
