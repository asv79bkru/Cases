<?php

declare(strict_types=1);

namespace CasesBot\Bot\Commands;

use CasesBot\Bot\VkTeamsClient;
use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\LlmCaseMatcher;
use CasesBot\Catalog\TagTaxonomy;
use CasesBot\Presentation\CaseDeckBuilder;
use CasesBot\Query\QueryParser;
use CasesBot\Storage\LocalPresentationsClient;
use Throwable;

/**
 * Команда «кейсы»: поиск по тегам (CatalogRepository::findByTags, §5.1.3), список найденных
 * кейсов с номерами слайдов и pptx-подборка по каждой затронутой исходной презентации (§5.1.4,
 * §5.1.6 ТЗ) — все слайды, помеченные «не кейс» в веб-интерфейсе (обложка, о компании,
 * контакты), плюс найденные по запросу кейсы, см. CaseDeckBuilder.
 *
 * При настроенном CaseDeckBuilder (опциональная зависимость, см. bin/poll.php) собирает и
 * отправляет pptx вложением; ссылка на оригинал целиком и номера слайдов отправляются в любом
 * случае следом (не только если сборка не удалась) — эксперт получает и готовую подборку, и
 * возможность свериться с исходником/собрать вручную.
 *
 * Принимает простые и составные теги через запятую, кавычки необязательны; каждое слово
 * или фраза ищется через словарь синонимов (TagTaxonomy) с резервом на прямое совпадение
 * по названию тега в базе (CatalogRepository::findTagsByName):
 *      кейсы ритейл
 *      кейсы производство, 1с
 *      кейсы честный знак
 * Разбор запроса — в QueryParser (§5.1.2), общем для чата и веб-интерфейса разметки;
 * по смыслу всей фразы команда пока не ищет, только по отдельным словам/фразам.
 *
 * Минимум кейсов в подборке (MIN_SLIDES_PER_DECK): если по тегам нашлось меньше, подборка
 * добирается «похожими» кейсами — сначала по связанным тегам (TagTaxonomy::relatedTags,
 * config/tag-relations.php), затем — по смыслу через LlmCaseMatcher; добранные кейсы
 * помечаются в сообщении отдельно. Присылать клиенту подборку из одного кейса — хуже, чем
 * дополнить её близкими по теме.
 *
 * Если ни одно слово запроса не совпало ни с одним известным тегом — при настроенном
 * LlmCaseMatcher (опциональная зависимость, см. bin/poll.php) запрос вместе с текстом всех
 * кейсов каталога уходит в LLM, которая либо подбирает id ближайших по смыслу кейсов, либо
 * явно говорит, что подходящих нет (§5.1.7 ТЗ), см. handleUnknownTags().
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
        private int $minSlidesPerDeck = 5,
        private ?LlmCaseMatcher $llmCaseMatcher = null,
        private ?CaseDeckBuilder $caseDeckBuilder = null,
    ) {
    }

    /** Ленивый парсер запроса (§5.1.2), общий с веб-интерфейсом разметки (public/tags.php). */
    private ?QueryParser $queryParser = null;

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

        // В подборке должно быть не меньше MIN_SLIDES_PER_DECK кейсов — добираем похожими.
        $similar = count($rows) < $this->minSlidesPerDeck
            ? $this->findSimilarCases($query, $tags, $rows)
            : [];

        if ($rows === [] && $similar === []) {
            $this->vkTeamsClient->sendText($chatId, $this->noResultsMessage());

            return;
        }

        $this->vkTeamsClient->sendText($chatId, $this->foundCasesMessage($rows, $similar));
        $this->sendSourcePresentations($chatId, array_merge($rows, $similar));
    }

    /**
     * Добор подборки до минимума, когда по тегам нашлось слишком мало кейсов:
     * 1) связанные теги (TagTaxonomy::relatedTags) — детерминированно и без LLM;
     * 2) если всё ещё меньше минимума и LlmCaseMatcher настроен — семантический добор:
     *    запрос и оставшиеся кейсы каталога уходят в LLM.
     * Добранные кейсы не повторяют уже выбранные и не превышают maxSlidesPerDeck суммарно.
     *
     * @param string[] $tags
     * @param array<int, array{id: int, source_file_id: string, slide_number: int, title: ?string}> $rows
     * @return array<int, array{id: int, source_file_id: string, slide_number: int, title: ?string}>
     */
    private function findSimilarCases(string $query, array $tags, array $rows): array
    {
        $similar = [];
        $selectedIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows
        );

        $hasCapacity = fn (): bool => count($rows) + count($similar) < $this->maxSlidesPerDeck;
        $needMore = fn (): bool => count($rows) + count($similar) < $this->minSlidesPerDeck;

        // 1. Связанные теги: «авто» -> «автопром», «машиностроение» и т.п.
        $related = [];
        foreach ($tags as $tag) {
            foreach ($this->tagTaxonomy->relatedTags($tag) as $relatedTag) {
                if (!in_array(mb_strtolower($relatedTag), array_map('mb_strtolower', $tags), true)) {
                    $related[mb_strtolower($relatedTag)] = $relatedTag;
                }
            }
        }

        if ($related !== [] && $needMore()) {
            $candidates = $this->catalog->findByTags(array_values($related), $this->maxSlidesPerDeck);
            foreach ($candidates as $row) {
                if (!$needMore() || !$hasCapacity()) {
                    break;
                }
                if (in_array((int) $row['id'], $selectedIds, true)) {
                    continue;
                }

                $similar[] = $row;
                $selectedIds[] = (int) $row['id'];
            }
        }

        // 2. Семантический добор через LLM по оставшимся кейсам каталога.
        if ($needMore() && $hasCapacity() && $this->llmCaseMatcher !== null) {
            $remaining = array_filter(
                $this->catalog->allForMatching(),
                static fn (array $case): bool => !in_array((int) $case['id'], $selectedIds, true)
            );

            try {
                $ids = $this->llmCaseMatcher->match($query, array_values($remaining));
            } catch (Throwable) {
                // LLM недоступна — отдаём то, что нашлось по тегам, без добора.
                $ids = [];
            }

            if ($ids !== []) {
                $candidates = $this->catalog->findByIds($ids, $this->maxSlidesPerDeck);
                foreach ($candidates as $row) {
                    if (!$needMore() || !$hasCapacity()) {
                        break;
                    }
                    if (in_array((int) $row['id'], $selectedIds, true)) {
                        continue;
                    }

                    $similar[] = $row;
                    $selectedIds[] = (int) $row['id'];
                }
            }
        }

        return $similar;
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
     * pptx-подборку (CaseDeckBuilder — все слайды «не кейс» из каталога плюс найденные по
     * запросу), и независимо от результата сборки — всегда также ссылку на оригинал презентации
     * целиком и номера слайдов (sendOriginalLink), чтобы эксперт при желании мог свериться
     * с исходником или собрать вручную, даже когда pptx-подборка уже пришла.
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
            $pptxPath = $this->caseDeckBuilder->build(
                $sourceFileId,
                $slideNumbers,
                $this->catalog->notCaseSlideNumbers($sourceFileId)
            );
        } catch (Throwable) {
            // Сборка pptx не удалась — sendOriginalLink ниже всё равно отдаёт эксперту рабочую
            // ссылку на оригинал, вместо того чтобы просто промолчать об ошибке.
            return;
        }

        try {
            $this->vkTeamsClient->sendFile($chatId, $pptxPath, "{$sourceFileId} — подборка по запросу");
        } catch (Throwable) {
            // см. комментарий выше — sendOriginalLink всё равно отдаст ссылку на оригинал.
        } finally {
            // Файл одноразовый — sendFile() уже передал его байты VK Teams в теле запроса,
            // хранить копию на сервере дальше незачем (storage/output иначе растёт без предела).
            if (is_file($pptxPath)) {
                unlink($pptxPath);
            }
        }
    }

    /**
     * Раньше файл отправлялся как вложение через VkTeamsClient::sendFile — но у собственного
     * nginx VK Teams есть лимит на размер тела запроса, и на реальном ~70МБ файле это давало
     * "413 Request Entity Too Large" ещё до бота. Ограничение на стороне самого API, обойти
     * его нельзя — поэтому вместо вложения отдаём прямую HTTP-ссылку (presentations-public/ на
     * :8080, «парадная» копия без заметок/комментариев, см. PublicCopyBuilder), которая размер
     * не ограничивает.
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
     * Разбор запроса в список тегов — общий алгоритм перенесён в QueryParser (§5.1.2):
     * там же его расширение операторами И/ИЛИ для веб-интерфейса. Команда «кейсы» ищет
     * по любому из распознанных тегов (CatalogRepository::findByTags, §5.1.3).
     *
     * @return string[]
     */
    public function parseQuery(string $query): array
    {
        return $this->queryParser()->parseQuery($query);
    }

    /** Парсер создаётся лениво — для исходных сценарийов чата он нужен не в каждом действии. */
    private function queryParser(): QueryParser
    {
        return $this->queryParser ??= new QueryParser($this->tagTaxonomy, $this->catalog);
    }

    /**
     * Список найденных кейсов (сначала точные совпадения по тегам, затем добранные «похожие»)
     * и пояснение, что будет в подборке ниже.
     *
     * @param array<int, array{title: ?string, slide_number: int}> $rows
     * @param array<int, array{title: ?string, slide_number: int}> $similar
     */
    private function foundCasesMessage(array $rows, array $similar = []): string
    {
        $lines = static fn (array $items): string => implode("\n", array_map(
            static fn (array $row): string => '— слайд ' . $row['slide_number'] . ': ' . ($row['title'] ?? '(без названия)'),
            $items
        ));

        $message = 'Нашёл ' . count($rows) . " кейс(ов) по тегам:\n{$lines($rows)}";

        if ($similar !== []) {
            $message .= "\n\nЧтобы в подборке было не меньше {$this->minSlidesPerDeck} кейсов, "
                . "добавил похожие по теме:\n{$lines($similar)}";
        }

        $tail = $this->caseDeckBuilder !== null
            ? 'Ниже — собранная pptx-подборка с этими слайдами и ссылка на оригинал(ы) презентации целиком.'
            : 'Ниже — ссылка(и) на оригинал(ы) презентации целиком, оставьте эти слайды вручную.';

        return "{$message}\n\n{$tail}";
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
