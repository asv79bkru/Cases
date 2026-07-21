<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

use CasesBot\Api\Providers\ChatProviderInterface;

/**
 * Резерв на случай, когда в запросе нет ни одного известного тега (TagTaxonomy/CatalogRepository::findTagsByName).
 * Вместо «ничего не нашлось» текст запроса вместе с текстом всех кейсов каталога (cases.content,
 * заполняется Indexer'ом при индексации — быстрый источник текста без повторного чтения презентаций)
 * уходит в LLM, которая либо подбирает id ближайших по смыслу кейсов, либо прямо отвечает, что
 * подходящих кейсов нет (§5.1.7 ТЗ: понятное сообщение вместо пустого/битого файла).
 */
class LlmCaseMatcher
{
    private const NO_MATCH_MARKER = 'НЕТ';

    private const SYSTEM_PROMPT = <<<'PROMPT'
        Тебе дан список кейсов (описаний успешных проектов) с их id и текстом, и запрос
        пользователя, который не совпал ни с одним известным тегом каталога. Подбери id кейсов,
        которые ближе всего подходят запросу по смыслу — по отрасли, продукту, технологии или
        клиенту, упомянутым в запросе.

        Ответь ТОЛЬКО списком id через запятую, без пояснений, без markdown, в порядке убывания
        релевантности. Если ни один кейс не подходит даже приблизительно, ответь ровно одним
        словом: НЕТ.
        PROMPT;

    public function __construct(
        private ChatProviderInterface $provider,
        private int $timeout = 60,
    ) {
    }

    /**
     * @param array<int, array{id: int, title: ?string, content: ?string, tags: ?string}> $cases
     * @return int[] Id кейсов в порядке релевантности по мнению LLM; пусто — совпадений нет.
     */
    public function match(string $query, array $cases): array
    {
        if ($cases === []) {
            return [];
        }

        $catalogText = implode("\n\n", array_map(
            static fn (array $case): string => "id={$case['id']} | теги: " . ($case['tags'] ?? '—') . "\n"
                . ($case['title'] ?? '(без названия)') . "\n" . ($case['content'] ?? ''),
            $cases
        ));

        $userMessage = "Запрос пользователя: {$query}\n\nКейсы в каталоге:\n{$catalogText}";

        $response = trim($this->provider->chat(self::SYSTEM_PROMPT, $userMessage, $this->timeout));

        if ($response === '' || mb_strtoupper($response) === self::NO_MATCH_MARKER) {
            return [];
        }

        $ids = [];
        foreach (explode(',', $response) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return $ids;
    }
}
