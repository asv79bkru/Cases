<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

/**
 * Справочник допустимых тегов и синонимов (например, «ритейл» = «розница»), см. config/tags.php.
 */
class TagTaxonomy
{
    // Соответствует CHECK-ограничению tags.category в storage/catalog/schema.sql.
    public const VALID_CATEGORIES = ['industry', 'product', 'technology', 'client'];

    /** @var array<string, array<string, array<int, string>>> */
    private array $dictionary;

    public function __construct(string $configPath)
    {
        $this->dictionary = require $configPath;
    }

    /** @return string[] Категории справочника (industry, product, technology, client). */
    public function categories(): array
    {
        return array_keys($this->dictionary);
    }

    /** @return array<string, string[]> Категория -> список уже существующих канонических тегов. */
    public function canonicalTags(): array
    {
        return array_map(static fn (array $tags): array => array_keys($tags), $this->dictionary);
    }

    /** Приводит слово к канонической форме тега в категории через синонимы, либо null, если не найдено. */
    public function normalize(string $category, string $term): ?string
    {
        $term = mb_strtolower(trim($term));
        $canonical = $this->dictionary[$category] ?? [];

        foreach ($canonical as $tag => $synonyms) {
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
     * @return array<int, array{category: string, tag: string}>
     */
    public function suggestTags(string $text): array
    {
        $haystack = mb_strtolower($text);
        $suggestions = [];

        foreach ($this->dictionary as $category => $tags) {
            foreach ($tags as $tag => $synonyms) {
                foreach (array_merge([$tag], $synonyms) as $needle) {
                    if ($needle !== '' && mb_stripos($haystack, mb_strtolower($needle)) !== false) {
                        $suggestions[] = ['category' => $category, 'tag' => $tag];
                        break;
                    }
                }
            }
        }

        return $suggestions;
    }

    /**
     * Ищет канонический тег по слову (точное имя или синоним) сразу во ВСЕХ категориях —
     * для запросов вида «кейсы ритейл» без явного указания категории. Слово может совпасть
     * с тегами в нескольких категориях сразу (например, «торговля» — и industry, и product).
     *
     * @return array<int, array{category: string, tag: string}>
     */
    public function normalizeInAnyCategory(string $term): array
    {
        $matches = [];
        foreach ($this->categories() as $category) {
            $canonical = $this->normalize($category, $term);
            if ($canonical !== null) {
                $matches[] = ['category' => $category, 'tag' => $canonical];
            }
        }

        return $matches;
    }

    /**
     * Разбирает строку вида "категория:тег, категория:тег" (формат ручного ввода в CLI,
     * тегов в заметках докладчика и ответа LLM) в список тегов. Пары с неизвестной категорией
     * или пустым тегом молча пропускаются — вызывающий код решает, предупреждать об этом или нет.
     *
     * @return array<int, array{category: string, tag: string}>
     */
    public static function parseTagList(string $line): array
    {
        $tags = [];

        foreach (explode(',', $line) as $pair) {
            [$category, $tag] = array_pad(explode(':', trim($pair), 2), 2, null);
            $category = $category !== null ? mb_strtolower(trim($category)) : null;
            $tag = $tag !== null ? mb_strtolower(trim($tag)) : null;

            if ($category === null || $tag === null || $tag === '') {
                continue;
            }
            if (!in_array($category, self::VALID_CATEGORIES, true)) {
                continue;
            }

            $tags[] = ['category' => $category, 'tag' => $tag];
        }

        return $tags;
    }

    /**
     * Разбирает строку и сразу приводит каждый тег к канонической форме через normalize()
     * (например, «industry:it» и «industry:ит» — один и тот же тег). Термин, которого нет
     * в справочнике (в т.ч. новый тег, ещё не добавленный в config/tags.php), остаётся как есть —
     * так найдутся и теги, добавленные в каталог напрямую при индексации.
     *
     * @return array<int, array{category: string, tag: string}>
     */
    public function parseAndNormalize(string $line): array
    {
        return array_map(
            fn (array $tag): array => [
                'category' => $tag['category'],
                'tag' => $this->normalize($tag['category'], $tag['tag']) ?? $tag['tag'],
            ],
            self::parseTagList($line)
        );
    }
}
