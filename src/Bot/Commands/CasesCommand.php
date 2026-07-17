<?php

declare(strict_types=1);

namespace CasesBot\Bot\Commands;

use CasesBot\Bot\VkTeamsClient;
use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\TagTaxonomy;
use CasesBot\Presentation\PresentationBuilder;
use CasesBot\Storage\LocalPresentationsClient;
use Throwable;

/**
 * Команда «кейсы»: поиск по тегам (CatalogRepository::findByTags, §5.1.3) и сборка подборки
 * (PresentationBuilder), отправка файла обратно в чат (§5.1.6 ТЗ).
 *
 * Принимает два вида запроса:
 *  - явный "категория:тег[, категория:тег...]" (как в CLI/заметках/LLM):
 *      кейсы technology:1с, industry:ритейл
 *  - просто слово (или несколько через запятую/пробел) без категории — ищется среди тегов
 *    во ВСЕХ категориях сразу, через словарь синонимов (TagTaxonomy) с резервом на прямое
 *    совпадение по названию тега в базе:
 *      кейсы ритейл
 *      кейсы производство
 * Полноценный QueryParser (разбор произвольного свободного текста, §5.1.2) — задел на будущее,
 * пока команда ищет по отдельным словам, а не по смыслу всей фразы.
 */
class CasesCommand implements CommandInterface
{
    private const TRIGGER = 'кейсы';

    public function __construct(
        private VkTeamsClient $vkTeamsClient,
        private CatalogRepository $catalog,
        private LocalPresentationsClient $presentations,
        private PresentationBuilder $presentationBuilder,
        private TagTaxonomy $tagTaxonomy,
        private int $maxSlidesPerDeck,
    ) {
    }

    public function matches(string $text): bool
    {
        return mb_stripos(ltrim($text), self::TRIGGER) === 0;
    }

    public function handle(string $chatId, string $text): void
    {
        $tags = $this->parseQuery($this->stripTrigger($text));

        if ($tags === []) {
            $this->vkTeamsClient->sendText(
                $chatId,
                "Не нашёл ни одного известного тега в запросе.\n"
                    . 'Примеры: «кейсы ритейл», «кейсы производство», '
                    . 'или явно «кейсы technology:1с, industry:ритейл» '
                    . '(категории: ' . implode(', ', TagTaxonomy::VALID_CATEGORIES) . ').'
            );

            return;
        }

        $rows = $this->catalog->findByTags($tags, $this->maxSlidesPerDeck);

        if ($rows === []) {
            $this->vkTeamsClient->sendText($chatId, $this->noResultsMessage());

            return;
        }

        $this->vkTeamsClient->sendText($chatId, $this->foundCasesMessage($rows));

        try {
            $slides = array_map(
                fn (array $row): array => [
                    'source_path' => $this->presentations->getFilePath($row['source_file_id']),
                    'slide_number' => $row['slide_number'],
                ],
                $rows
            );

            $topic = implode(', ', array_map(
                static fn (array $t): string => "{$t['category']}:{$t['tag']}",
                $tags
            ));

            $path = $this->presentationBuilder->build($topic, $slides);
            $this->vkTeamsClient->sendFile($chatId, $path);
        } catch (Throwable $e) {
            $this->vkTeamsClient->sendText($chatId, "Не удалось собрать подборку: {$e->getMessage()}");
        }
    }

    private function stripTrigger(string $text): string
    {
        $rest = mb_substr(ltrim($text), mb_strlen(self::TRIGGER));

        return ltrim($rest, " \t\n\r:—-");
    }

    /**
     * Части, разделённые запятой, могут быть явным "категория:тег" (тогда категория уже известна)
     * либо просто словом/несколькими словами через пробел — каждое слово ищется отдельно во всех
     * категориях сразу (TagTaxonomy::normalizeInAnyCategory, с резервом на CatalogRepository::findTagsByName
     * для тегов, которых ещё нет в config/tags.php). Дубли по (категория, тег) схлопываются.
     *
     * @return array<int, array{category: string, tag: string}>
     */
    public function parseQuery(string $query): array
    {
        $tags = [];

        foreach (preg_split('/[,\n]+/u', $query) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, ':')) {
                foreach (TagTaxonomy::parseTagList($part) as $pair) {
                    $canonical = $this->tagTaxonomy->normalize($pair['category'], $pair['tag']) ?? $pair['tag'];
                    $tags[] = ['category' => $pair['category'], 'tag' => $canonical];
                }

                continue;
            }

            foreach (preg_split('/\s+/u', $part) as $word) {
                $word = trim($word);
                if ($word === '') {
                    continue;
                }

                $found = $this->tagTaxonomy->normalizeInAnyCategory($word);
                if ($found === []) {
                    $found = $this->catalog->findTagsByName($word);
                }

                array_push($tags, ...$found);
            }
        }

        $unique = [];
        foreach ($tags as $tag) {
            $unique["{$tag['category']}:{$tag['tag']}"] = $tag;
        }

        return array_values($unique);
    }

    /** @param array<int, array{title: ?string}> $rows */
    private function foundCasesMessage(array $rows): string
    {
        $titles = implode("\n", array_map(
            static fn (array $row): string => '— ' . ($row['title'] ?? '(без названия)'),
            $rows
        ));

        return 'Нашёл ' . count($rows) . " кейс(ов):\n{$titles}\n\nСобираю подборку, минуту…";
    }

    private function noResultsMessage(): string
    {
        $available = $this->catalog->allTags();
        if ($available === []) {
            return 'По этому запросу ничего не нашлось. Каталог кейсов пока пуст.';
        }

        $list = implode(', ', array_map(
            static fn (array $t): string => "{$t['category']}:{$t['tag']}",
            $available
        ));

        return "По этому запросу ничего не нашлось.\nДоступные темы: {$list}";
    }
}
