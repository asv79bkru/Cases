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
 * QueryParser (разбор свободного текста в теги через синонимы, §5.1.2) ещё не реализован — команда
 * принимает теги явно, в том же формате "категория:тег", что и везде в проекте (CLI, заметки, LLM).
 * Тег при этом проходит через TagTaxonomy::normalize(), так что синонимы из config/tags.php работают
 * и здесь — «industry:it» и «industry:ит» находят один и тот же тег:
 *   кейсы technology:1с, industry:ритейл
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
        $tags = $this->tagTaxonomy->parseAndNormalize($this->stripTrigger($text));

        if ($tags === []) {
            $this->vkTeamsClient->sendText(
                $chatId,
                "Не разобрал теги запроса.\nПример: «кейсы technology:1с, industry:ритейл» "
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
