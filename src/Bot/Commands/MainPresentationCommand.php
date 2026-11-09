<?php

declare(strict_types=1);

namespace CasesBot\Bot\Commands;

use CasesBot\Bot\VkTeamsClient;
use CasesBot\Catalog\CatalogRepository;
use CasesBot\Presentation\MainPresentationBuilder;
use CasesBot\Storage\LocalPresentationsClient;
use Throwable;

/**
 * Команда «/main_presentation» — ссылка на скачивание основной презентации направления,
 * собранной из слайдов с тегом «основной» (плюс служебные слайды «не кейс»: обложка,
 * о компании, контакты; см. MainPresentationBuilder).
 *
 * Использование: «/main_presentation Экспертиза направления» — текст после триггера
 * (название направления) пока ни на что не влияет: в каталоге одна презентация источника.
 * Когда направлений станет несколько, по этому тексту будет выбираться нужное.
 *
 * Сборка идёт в presentations-public/ под постоянным именем (MainPresentationBuilder),
 * в чат уходит прямая HTTP-ссылка на :8080 — как ссылка на оригинал в CasesCommand
 * (вложение sendFile на больших pptx упирается в лимит тела запроса nginx VK Teams).
 *
 * Слайды с тегом «основной» ищет CatalogRepository::slidesByTag (регистр тега не важен),
 * тег ставит эксперт в веб-интерфейсе разметки. Если в каталоге такого тега нет ни у
 * одного видимого кейса — команда отвечает подсказкой, вместо того чтобы присылать
 * пустую ссылку.
 */
class MainPresentationCommand implements CommandInterface
{
    private const TRIGGER = '/main_presentation';

    /** Тег, которым эксперт отмечает слайды, попадающие в основную презентацию. */
    private const MAIN_TAG = 'основной';

    /** Текст ссылки в чате — фиксированный, совпадает с именем презентации. */
    private const LINK_TEXT = 'Экспертиза направления Бизнес-автоматизации';

    public function __construct(
        private VkTeamsClient $vkTeamsClient,
        private CatalogRepository $catalog,
        private LocalPresentationsClient $presentations,
        private MainPresentationBuilder $mainPresentationBuilder,
    ) {
    }

    public function matches(string $text): bool
    {
        return mb_stripos(ltrim($text), self::TRIGGER) === 0;
    }

    public function handle(string $chatId, string $text): void
    {
        $groups = $this->catalog->slidesByTag(self::MAIN_TAG);

        if ($groups === []) {
            $this->vkTeamsClient->sendText(
                $chatId,
                "Слайдов с тегом «" . self::MAIN_TAG . "» в каталоге пока нет.\n"
                    . 'Отметьте основные кейсы этим тегом в веб-интерфейсе разметки — '
                    . 'после этого презентация соберётся здесь.'
            );

            return;
        }

        foreach ($groups as $group) {
            $slideNumbers = $group['slide_numbers'];
            $sourceFileId = $group['source_file_id'];

            try {
                $this->mainPresentationBuilder->build(
                    $sourceFileId,
                    $slideNumbers,
                    $this->catalog->notCaseSlideNumbers($sourceFileId)
                );
            } catch (Throwable $e) {
                $this->vkTeamsClient->sendText(
                    $chatId,
                    "Не удалось собрать основную презентацию по «{$group['source_file_name']}»: {$e->getMessage()}"
                );

                continue;
            }

            $this->sendDeckLink($chatId, $sourceFileId);
        }
    }

    /**
     * Ссылка без текста-обрамления: одна кликабельная строка «Экспертиза направления
     * Бизнес-автоматизации» (имя файла), больше ничего.
     */
    private function sendDeckLink(string $chatId, string $sourceFileId): void
    {
        $url = $this->presentations->getPublicUrlByName($this->mainPresentationBuilder->deckName($sourceFileId));
        $link = '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
            . htmlspecialchars(self::LINK_TEXT, ENT_QUOTES) . '</a>';

        $this->vkTeamsClient->sendHtml($chatId, $link);
    }
}
