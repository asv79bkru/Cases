<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

/**
 * Справочник допустимых тегов и синонимов (например, «ритейл» = «розница»), см. config/tags.php.
 */
class TagTaxonomy
{
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
}
