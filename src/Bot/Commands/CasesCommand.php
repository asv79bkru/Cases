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
 * Принимает простые теги через запятую и/или пробел, без категорий — каждое слово ищется
 * через словарь синонимов (TagTaxonomy) с резервом на прямое совпадение по названию тега
 * в базе (CatalogRepository::findTagsByName):
 *      кейсы ритейл
 *      кейсы производство, 1с
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
                    . 'Примеры: «кейсы ритейл», «кейсы производство, 1с».'
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
     * Разбивает запрос на отдельные слова (через запятую/перевод строки/пробел) и приводит
     * каждое к каноническому тегу через словарь синонимов (TagTaxonomy::normalize), с резервом
     * на прямое совпадение по названию тега в базе (CatalogRepository::findTagsByName) для
     * тегов, которых ещё нет в config/tags.php. Слово, не найденное ни там, ни там, молча
     * пропускается. Дубли схлопываются.
     *
     * @return string[]
     */
    public function parseQuery(string $query): array
    {
        $tags = [];

        foreach (preg_split('/[,\n]+/u', $query) as $part) {
            foreach (preg_split('/\s+/u', trim($part)) as $word) {
                $word = trim($word);
                if ($word === '') {
                    continue;
                }

                $found = $this->tagTaxonomy->normalize($word) ?? $this->catalog->findTagsByName($word);
                if ($found !== null) {
                    $tags[mb_strtolower($found)] = $found;
                }
            }
        }

        return array_values($tags);
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

        return "По этому запросу ничего не нашлось.\nДоступные темы: " . implode(', ', $available);
    }
}
