<?php

declare(strict_types=1);

namespace CasesBot\Presentation;

use CasesBot\Storage\LocalPresentationsClient;
use RuntimeException;

/**
 * Собирает pptx-подборку из одной исходной презентации: все слайды, помеченные в
 * веб-интерфейсе как «не кейс» (обложка, о компании, контакты — флаг is_not_case в
 * каталоге, см. Indexer/public-tags.php), плюс слайды, найденные по запросу пользователя
 * (CasesCommand). Остальные кейсы исходной презентации (не совпавшие с запросом) в
 * подборку не попадают. Список «не-кейсов» передаёт вызывающий код — он уже читает его
 * из CatalogRepository (слайды из базы, а не из заметок исходника).
 *
 * Слайды остаются в исходном порядке презентации — так подборка читается как урезанная
 * версия оригинала, а не набор вырванных слайдов. Сборка — через SlideCloner
 * (OOXML-«хирургия», побайтовое копирование частей пакета), а не через высокоуровневый
 * API python-pptx, чтобы не терять форматирование (см. SlideCloner).
 *
 * PDF здесь сознательно не используется: LibreOffice headless на Alpine/musl (текущий базовый
 * образ) падает на старте с SIGABRT ещё до конвертации (баг совместимости, не наших слайдов) —
 * проверено на реальном сервере. Отдаём pptx напрямую.
 */
class CaseDeckBuilder
{
    public function __construct(
        private SlideCloner $slideCloner,
        private LocalPresentationsClient $presentations,
        private string $outputDir,
    ) {
    }

    /**
     * @param int[] $matchedSlideNumbers Номера слайдов-кейсов, найденных по запросу в этом файле.
     * @param int[] $notCaseSlideNumbers Номера слайдов «не кейс» из каталога (is_not_case = 1):
     *        обложка/о компании/контакты, всегда остаются в подборке.
     * @return string Путь к собранному pptx.
     */
    public function build(string $sourceFileId, array $matchedSlideNumbers, array $notCaseSlideNumbers = []): string
    {
        $keepNumbers = array_values(array_unique(array_merge(
            array_map('intval', $notCaseSlideNumbers),
            array_map('intval', $matchedSlideNumbers)
        )));
        sort($keepNumbers);

        if ($keepNumbers === []) {
            throw new RuntimeException("Нечего собирать: для «{$sourceFileId}» не выбрано ни одного слайда");
        }

        $sourcePath = $this->presentations->getFilePath($sourceFileId);

        $outputPath = rtrim($this->outputDir, '/\\') . '/' . $this->generateFileName($sourceFileId);
        // prune вместо clone: пакет исходника остаётся исходным (PowerPoint открывает без
        // ремонта), из него удаляются не попавшие в подборку слайды — порядок сохранён.
        $this->slideCloner->prune($sourcePath, $keepNumbers, $outputPath);

        return $outputPath;
    }

    private function generateFileName(string $sourceFileId): string
    {
        $slug = trim((string) preg_replace('/[^0-9A-Za-zА-Яа-яЁё]+/u', '_', pathinfo($sourceFileId, PATHINFO_FILENAME)), '_');

        return sprintf('%s_%s_%s.pptx', $slug, date('Ymd_His'), substr(md5(uniqid('', true)), 0, 6));
    }
}
