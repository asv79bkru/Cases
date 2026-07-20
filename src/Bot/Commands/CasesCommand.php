<?php

declare(strict_types=1);

namespace CasesBot\Bot\Commands;

use CasesBot\Bot\VkTeamsClient;
use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\TagTaxonomy;
use CasesBot\Storage\LocalPresentationsClient;
use Throwable;

/**
 * Команда «кейсы»: поиск по тегам (CatalogRepository::findByTags, §5.1.3), список найденных
 * кейсов с номерами слайдов и скачивание исходной презентации(й) целиком (§5.1.6 ТЗ).
 *
 * Сборка нового pptx из найденных слайдов (PresentationBuilder/SlideCloner) сюда сознательно
 * не подключена — сгенерированные файлы у части пользователей не открывались в PowerPoint,
 * причина не найдена (структура OOXML при этом проверена и чиста, подозрение на передачу).
 * Пока эксперт получает оригинал(ы) без изменений — они точно открываются — и номера слайдов,
 * которые нужно оставить, чтобы собрать подборку вручную.
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
        $this->sendSourcePresentations($chatId, $rows);
    }

    /**
     * Присылает по одной ссылке на каждый source_file_id, встречающийся в найденных кейсах,
     * с подписью, какие слайды из неё нужно оставить.
     *
     * Раньше файл отправлялся как вложение через VkTeamsClient::sendFile — но у собственного
     * nginx VK Teams есть лимит на размер тела запроса, и на реальном ~70МБ файле это давало
     * "413 Request Entity Too Large" ещё до бота. Ограничение на стороне самого API, обойти
     * его нельзя — поэтому вместо вложения отдаём прямую HTTP-ссылку (presentations/ на :8080,
     * см. docker/entrypoint.sh), которая размер не ограничивает.
     *
     * @param array<int, array{source_file_id: string, slide_number: int}> $rows
     */
    private function sendSourcePresentations(string $chatId, array $rows): void
    {
        $slidesByFile = [];
        foreach ($rows as $row) {
            $slidesByFile[$row['source_file_id']][] = $row['slide_number'];
        }

        foreach ($slidesByFile as $sourceFileId => $slideNumbers) {
            sort($slideNumbers);

            try {
                $url = $this->presentations->getPublicUrl($sourceFileId);
                $this->vkTeamsClient->sendText(
                    $chatId,
                    "{$sourceFileId} — оставьте слайды: " . implode(', ', $slideNumbers) . "\n{$url}"
                );
            } catch (Throwable $e) {
                $this->vkTeamsClient->sendText(
                    $chatId,
                    "Не удалось подготовить ссылку на «{$sourceFileId}»: {$e->getMessage()}"
                );
            }
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

    /** @param array<int, array{title: ?string, slide_number: int}> $rows */
    private function foundCasesMessage(array $rows): string
    {
        $lines = implode("\n", array_map(
            static fn (array $row): string => "— слайд {$row['slide_number']}: " . ($row['title'] ?? '(без названия)'),
            $rows
        ));

        return 'Нашёл ' . count($rows) . " кейс(ов) — оставьте эти слайды:\n{$lines}\n\n"
            . 'Ниже — ссылка(и) на оригинал(ы) презентации целиком (сборка подборки автоматически пока отключена).';
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
