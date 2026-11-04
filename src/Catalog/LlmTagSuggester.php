<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

use CasesBot\Api\Providers\ChatProviderInterface;

/**
 * Отправляет содержимое кейса (заголовок + текст слайда + комментарии рецензентов) в LLM
 * и просит дополнить теги. Ответ НЕ становится тегом напрямую: предложения кладутся
 * в очередь tag_suggestions (CatalogRepository::refreshSuggestions) и становятся тегами
 * только после подтверждения эксперта в веб-интерфейсе (public/tags.php).
 */
class LlmTagSuggester
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Ты помогаешь размечать кейсы (истории успешных проектов) простыми тегами для поиска
        в каталоге (без категорий — отрасль, продукт, технология и клиент вперемешку в одном
        списке). По возможности используй уже существующие теги из списка ниже, а не придумывай
        новые синонимы для того же понятия (например, для автомобилей — «авто», если такое есть
        в списке, а не «автопром»). Если ни один существующий тег не подходит, можешь
        предложить новый.

        Ответь ТОЛЬКО списком тегов через запятую, без пояснений, без markdown, без пустого
        ответа, если хоть один тег подобрать возможно. Каждый тег — одно-два слова в нижнем
        регистре. Если по тексту невозможно определить ни одного тега, ответь пустой строкой.
        PROMPT;

    public function __construct(
        private ChatProviderInterface $provider,
        private TagTaxonomy $tagTaxonomy,
        private int $timeout = 60,
    ) {
    }

    /**
     * @return string[]
     */
    public function suggest(string $title, string $content, string $comments = ''): array
    {
        $known = implode(', ', $this->tagTaxonomy->canonicalTags());

        $userMessage = "Существующие теги в каталоге:\n{$known}\n"
            . "Заголовок кейса: {$title}\n\nТекст кейса:\n{$content}";

        // Комментарии рецензентов к слайду — дополнительный контекст: в них часто названы
        // клиент, отрасль и результат проекта. Во внешний файл они не попадают — только
        // в очередь предложений тегов внутренней базы.
        $comments = trim($comments);
        if ($comments !== '') {
            $userMessage .= "\n\nКомментарии рецензентов к слайду:\n{$comments}";
        }

        $response = $this->provider->chat(self::SYSTEM_PROMPT, $userMessage, $this->timeout);

        return TagTaxonomy::parseTagList($response);
    }
}
