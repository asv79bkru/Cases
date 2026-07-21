<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

/**
 * Справочник допустимых тегов и синонимов (например, «ритейл» = «розница»), см. config/tags.php.
 */
class TagTaxonomy
{
    /** @var array<string, array<int, string>> */
    private array $dictionary;

    public function __construct(string $configPath)
    {
        $this->dictionary = require $configPath;
    }

    /** @return string[] Уже существующие канонические теги. */
    public function canonicalTags(): array
    {
        return array_keys($this->dictionary);
    }

    /** Приводит слово к канонической форме тега через синонимы, либо null, если не найдено. */
    public function normalize(string $term): ?string
    {
        $term = mb_strtolower(trim($term));

        foreach ($this->dictionary as $tag => $synonyms) {
            if (mb_strtolower($tag) === $term) {
                return $tag;
            }
            foreach ($synonyms as $synonym) {
                if (mb_strtolower($synonym) === $term) {
                    return $tag;
                }
            }
        }

        return null;
    }

    /**
     * Предлагает канонические теги по свободному тексту слайда (вхождение тега/синонима подстрокой).
     * Эксперт подтверждает или правит предложение при индексации — не финальное решение (§5.1.8 ТЗ).
     *
     * @return string[]
     */
    public function suggestTags(string $text): array
    {
        $haystack = mb_strtolower($text);
        $suggestions = [];

        foreach ($this->dictionary as $tag => $synonyms) {
            foreach (array_merge([$tag], $synonyms) as $needle) {
                if ($needle !== '' && mb_stripos($haystack, mb_strtolower($needle)) !== false) {
                    $suggestions[] = $tag;
                    break;
                }
            }
        }

        return $suggestions;
    }

    /**
     * Разбирает строку вида "тег, тег" (формат ручного ввода в CLI, тегов в заметках
     * докладчика и ответа LLM) в список тегов. Пустые части молча пропускаются.
     *
     * @return string[]
     */
    public static function parseTagList(string $line): array
    {
        $tags = [];

        foreach (explode(',', $line) as $tag) {
            $tag = mb_strtolower(trim($tag));
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * Разбирает строку и сразу приводит каждый тег к канонической форме через normalize()
     * (например, «it» и «ит» — один и тот же тег). Термин, которого нет в справочнике
     * (в т.ч. новый тег, ещё не добавленный в config/tags.php), остаётся как есть — так
     * найдутся и теги, добавленные в каталог напрямую при индексации.
     *
     * @return string[]
     */
    public function parseAndNormalize(string $line): array
    {
        return array_map(
            fn (string $tag): string => $this->normalize($tag) ?? $tag,
            self::parseTagList($line)
        );
    }
}
