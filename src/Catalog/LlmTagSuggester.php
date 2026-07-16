<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

use CasesBot\Api\Providers\ChatProviderInterface;

/**
 * Отправляет содержимое кейса (заголовок + текст слайда) в LLM и просит дополнить теги
 * (§5.1.8 ТЗ: тегирование не полностью автоматическое — результат идёт в подсказку эксперту
 * наравне с тегами из TagTaxonomy и из заметок докладчика, а не сохраняется напрямую).
 */
class LlmTagSuggester
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Ты помогаешь размечать кейсы (истории успешных проектов) тегами для поиска в каталоге.
        Категории тегов строго: industry (отрасль клиента), product (продукт/система), technology
        (технология), client (если в тексте явно назван клиент). По возможности используй уже
        существующие теги из списка ниже, а не придумывай новые синонимы для того же понятия.
        Если ни один существующий тег не подходит, можешь предложить новый тег в одной из
        разрешённых категорий.

        Ответь ТОЛЬКО списком тегов через запятую в формате "категория:тег", без пояснений,
        без markdown, без пустого ответа, если хоть один тег подобрать возможно. Каждый тег —
        одно-два слова в нижнем регистре. Если по тексту невозможно определить ни одного тега,
        ответь пустой строкой.
        PROMPT;

    public function __construct(
        private ChatProviderInterface $provider,
        private TagTaxonomy $tagTaxonomy,
        private int $timeout = 60,
    ) {
    }

    /** @return array<int, array{category: string, tag: string}> */
    public function suggest(string $title, string $content): array
    {
        $known = '';
        foreach ($this->tagTaxonomy->canonicalTags() as $category => $tags) {
            if ($tags !== []) {
                $known .= "{$category}: " . implode(', ', $tags) . "\n";
            }
        }

        $userMessage = "Существующие теги в каталоге:\n{$known}\n"
            . "Заголовок кейса: {$title}\n\nТекст кейса:\n{$content}";

        $response = $this->provider->chat(self::SYSTEM_PROMPT, $userMessage, $this->timeout);

        return TagTaxonomy::parseTagList($response);
    }
}
