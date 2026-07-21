<?php

declare(strict_types=1);

namespace CasesBot\Bot\Commands;

use CasesBot\Bot\VkTeamsClient;
use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\LlmCaseMatcher;
use CasesBot\Catalog\TagTaxonomy;
use CasesBot\Presentation\CaseDeckBuilder;
use CasesBot\Storage\LocalPresentationsClient;
use Throwable;

/**
 * Команда «кейсы»: поиск по тегам (CatalogRepository::findByTags, §5.1.3), список найденных
 * кейсов с номерами слайдов и pptx-подборка по каждой затронутой исходной презентации (§5.1.4,
 * §5.1.6 ТЗ) — все слайды без метки #кейс# (обложка, о компании, контакты) плюс найденные по
 * запросу кейсы, см. CaseDeckBuilder.
 *
 * При настроенном CaseDeckBuilder (опциональная зависимость, см. bin/poll.php) собирает и
 * отправляет pptx вложением; ссылка на оригинал целиком и номера слайдов отправляются в любом
 * случае следом (не только если сборка не удалась) — эксперт получает и готовую подборку, и
 * возможность свериться с исходником/собрать вручную. Ранее автосборка была отключена совсем —
 * у части пользователей собранные файлы не открывались в PowerPoint, причина не найдена
 * (структура OOXML при этом проверена и чиста, подозрение на передачу) — пробуем снова:
 * возможно, дело было в передаче конкретного файла, а не в самой сборке.
 *
 * Принимает простые теги через запятую и/или пробел, без категорий — каждое слово ищется
 * через словарь синонимов (TagTaxonomy) с резервом на прямое совпадение по названию тега
 * в базе (CatalogRepository::findTagsByName):
 *      кейсы ритейл
 *      кейсы производство, 1с
 * Полноценный QueryParser (разбор произвольного свободного текста, §5.1.2) — задел на будущее,
 * пока команда ищет по отдельным словам, а не по смыслу всей фразы.
 *
 * Если ни одно слово запроса не совпало ни с одним известным тегом — при настроенном
 * LlmCaseMatcher (опциональная зависимость, см. bin/poll.php) запрос вместе с текстом всех
 * кейсов каталога уходит в LLM, которая либо подбирает ближайшие по смыслу кейсы, либо явно
 * говорит, что подходящих нет (§5.1.7 ТЗ), см. handleUnknownTags().
 */
class CasesCommand implements CommandInterface
{
    private const TRIGGER = 'кейсы';

    /** Текст ссылки на исходную презентацию — фиксированный, одинаковый для любого файла. */
    private const PRESENTATION_LINK_TEXT = 'Экспертиза 1С. Все кейсы.';

    public function __construct(
        private VkTeamsClient $vkTeamsClient,
        private CatalogRepository $catalog,
        private LocalPresentationsClient $presentations,
        private TagTaxonomy $tagTaxonomy,
        private int $maxSlidesPerDeck,
        private ?LlmCaseMatcher $llmCaseMatcher = null,
        private ?CaseDeckBuilder $caseDeckBuilder = null,
    ) {
    }

    public function matches(string $text): bool
    {
        return mb_stripos(ltrim($text), self::TRIGGER) === 0;
    }

    public function handle(string $chatId, string $text): void
    {
        $query = $this->stripTrigger($text);
        $tags = $this->parseQuery($query);

        if ($tags === []) {
            $this->handleUnknownTags($chatId, $query);

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
     * Ни одно слово запроса не совпало с известным тегом (ни в TagTaxonomy, ни в каталоге).
     * Без LlmCaseMatcher — прежнее поведение: подсказка с примерами. С ним — фолбэк на LLM
     * (§5.1.7 ТЗ, задел на будущий QueryParser): в модель уходит запрос целиком и текст всех
     * видимых кейсов каталога (CatalogRepository::allForMatching, текст уже в cases.content),
     * она либо подбирает id ближайших по смыслу кейсов, либо сообщает, что подходящих нет.
     */
    private function handleUnknownTags(string $chatId, string $query): void
    {
        if ($this->llmCaseMatcher === null) {
            $this->vkTeamsClient->sendText(
                $chatId,
                "Не нашёл ни одного известного тега в запросе.\n"
                    . 'Примеры: «кейсы ритейл», «кейсы производство, 1с».'
            );

            return;
        }

        $cases = $this->catalog->allForMatching();
        if ($cases === []) {
            $this->vkTeamsClient->sendText($chatId, 'По этому запросу ничего не нашлось. Каталог кейсов пока пуст.');

            return;
        }

        try {
            $ids = $this->llmCaseMatcher->match($query, $cases);
        } catch (Throwable $e) {
            $this->vkTeamsClient->sendText(
                $chatId,
                "Не нашёл ни одного известного тега в запросе, а подбор через LLM не удался: {$e->getMessage()}"
            );

            return;
        }

        $rows = $ids !== [] ? $this->catalog->findByIds($ids, $this->maxSlidesPerDeck) : [];

        if ($rows === []) {
            $this->vkTeamsClient->sendText($chatId, $this->noResultsMessage());

            return;
        }

        $this->vkTeamsClient->sendText(
            $chatId,
            "Не нашёл известных тегов в запросе — вот что подобрала LLM по смыслу:\n\n"
                . $this->foundCasesMessage($rows)
        );
        $this->sendSourcePresentations($chatId, $rows);
    }

    /**
     * По каждому source_file_id, встречающемуся в найденных кейсах: пытается собрать и отправить
     * pptx-подборку (CaseDeckBuilder — все слайды без метки #кейс# плюс найденные по запросу), и
     * независимо от результата сборки — всегда также ссылку на оригинал презентации целиком и
     * номера слайдов (sendOriginalLink), чтобы эксперт при желании мог свериться с исходником
     * или собрать вручную, даже когда pptx-подборка уже пришла.
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

            $this->trySendDeck($chatId, $sourceFileId, $slideNumbers);
            $this->sendOriginalLink($chatId, $sourceFileId, $slideNumbers);
        }
    }

    /** @param int[] $slideNumbers */
    private function trySendDeck(string $chatId, string $sourceFileId, array $slideNumbers): void
    {
        if ($this->caseDeckBuilder === null) {
            return;
        }

        try {
            $pptxPath = $this->caseDeckBuilder->build($sourceFileId, $slideNumbers);
            $this->vkTeamsClient->sendFile($chatId, $pptxPath, "{$sourceFileId} — подборка по запросу");
        } catch (Throwable) {
            // Сборка pptx не удалась — sendOriginalLink ниже всё равно отдаёт эксперту рабочую
            // ссылку на оригинал, вместо того чтобы просто промолчать об ошибке.
        }
    }

    /**
     * Раньше файл отправлялся как вложение через VkTeamsClient::sendFile — но у собственного
     * nginx VK Teams есть лимит на размер тела запроса, и на реальном ~70МБ файле это давало
     * "413 Request Entity Too Large" ещё до бота. Ограничение на стороне самого API, обойти
     * его нельзя — поэтому вместо вложения отдаём прямую HTTP-ссылку (presentations/ на :8080,
     * см. docker/entrypoint.sh), которая размер не ограничивает.
     *
     * @param int[] $slideNumbers
     */
    private function sendOriginalLink(string $chatId, string $sourceFileId, array $slideNumbers): void
    {
        try {
            $url = $this->presentations->getPublicUrl($sourceFileId);
            $link = '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
                . htmlspecialchars(self::PRESENTATION_LINK_TEXT, ENT_QUOTES) . '</a>';
            $this->vkTeamsClient->sendHtml(
                $chatId,
                htmlspecialchars($sourceFileId, ENT_QUOTES) . ' — оставьте слайды: '
                    . implode(', ', $slideNumbers) . "\n{$link}"
            );
        } catch (Throwable $e) {
            $this->vkTeamsClient->sendText(
                $chatId,
                "Не удалось подготовить ссылку на «{$sourceFileId}»: {$e->getMessage()}"
            );
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

        $tail = $this->caseDeckBuilder !== null
            ? 'Ниже — собранная pptx-подборка с этими слайдами и ссылка на оригинал(ы) презентации целиком.'
            : 'Ниже — ссылка(и) на оригинал(ы) презентации целиком, оставьте эти слайды вручную.';

        return 'Нашёл ' . count($rows) . " кейс(ов):\n{$lines}\n\n{$tail}";
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
