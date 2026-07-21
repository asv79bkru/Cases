<?php

declare(strict_types=1);

namespace CasesBot\Presentation;

use CasesBot\Storage\LocalPresentationsClient;
use RuntimeException;

/**
 * Собирает pptx-подборку из одной исходной презентации: все слайды без метки #кейс# в заметках
 * докладчика (обложка, о компании, контакты — та же метка, что при индексации, см.
 * SlideTextExtractor/Indexer) плюс слайды, найденные по запросу пользователя (CasesCommand).
 * Остальные кейсы исходной презентации (не совпавшие с запросом) в подборку не попадают.
 *
 * Слайды остаются в исходном порядке презентации — так подборка читается как урезанная версия
 * оригинала, а не набор вырванных слайдов. Сборка — через SlideCloner (OOXML-«хирургия»,
 * побайтовое копирование частей пакета), а не через высокоуровневый API python-pptx, чтобы не
 * терять форматирование (см. SlideCloner).
 *
 * PDF здесь сознательно не используется: LibreOffice headless на Alpine/musl (текущий базовый
 * образ) падает на старте с SIGABRT ещё до конвертации (баг совместимости, не наших слайдов) —
 * проверено на реальном сервере. Отдаём pptx напрямую.
 */
class CaseDeckBuilder
{
    public function __construct(
        private SlideTextExtractor $slideTextExtractor,
        private SlideCloner $slideCloner,
        private LocalPresentationsClient $presentations,
        private string $outputDir,
    ) {
    }

    /**
     * @param int[] $matchedSlideNumbers Номера слайдов-кейсов, найденных по запросу в этом файле.
     * @return string Путь к собранному pptx.
     */
    public function build(string $sourceFileId, array $matchedSlideNumbers): string
    {
        $sourcePath = $this->presentations->getFilePath($sourceFileId);
        $allSlides = $this->slideTextExtractor->extract($sourcePath);

        $matched = array_flip($matchedSlideNumbers);
        $keepNumbers = [];
        foreach ($allSlides as $slide) {
            if (!$slide['is_case'] || isset($matched[$slide['slide_number']])) {
                $keepNumbers[] = $slide['slide_number'];
            }
        }

        if ($keepNumbers === []) {
            throw new RuntimeException("Нечего собирать: в «{$sourceFileId}» не осталось слайдов после фильтрации");
        }

        $outputPath = rtrim($this->outputDir, '/\\') . '/' . $this->generateFileName($sourceFileId);
        $this->slideCloner->clone(
            array_map(
                static fn (int $slideNumber): array => ['source_path' => $sourcePath, 'slide_number' => $slideNumber],
                $keepNumbers
            ),
            $outputPath
        );

        return $outputPath;
    }

    private function generateFileName(string $sourceFileId): string
    {
        $slug = trim((string) preg_replace('/[^0-9A-Za-zА-Яа-яЁё]+/u', '_', pathinfo($sourceFileId, PATHINFO_FILENAME)), '_');

        return sprintf('%s_%s_%s.pptx', $slug, date('Ymd_His'), substr(md5(uniqid('', true)), 0, 6));
    }
}
