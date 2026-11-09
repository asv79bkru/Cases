<?php

declare(strict_types=1);

namespace CasesBot\Presentation;

use CasesBot\Storage\LocalPresentationsClient;
use RuntimeException;

/**
 * «Основная» презентация направления (команда /main_presentation): урезанная копия исходника,
 * в которой из кейсов остаются только слайды, отмеченные экспертом тегом «основной»
 * (CatalogRepository::slidesByTag), плюс служебные слайды «не кейс» (обложка, о компании,
 * контакты — CatalogRepository::notCaseSlideNumbers, как в подборке CaseDeckBuilder).
 *
 * В отличие от одноразовой подборки CaseDeckBuilder (случайное имя в storage/output, файл
 * удаляется после отправки как вложение) эта копия живёт в presentations-public/ навсегда:
 * команда присылает прямую HTTP-ссылку (:8080), а не вложение. Имя копии — из
 * $nameOverrides (config['presentations']['main_name_overrides'], исходник -> имя основной
 * презентации), например «Кейсы направления Бизнес автоматизации.pptx» ->
 * «Экспертиза направления Бизнес-автоматизации.pptx»; у полной копии каталога то же имя
 * исходника носить не должна, поэтому полная копия переименовывается отдельно
 * (config['presentations']['public_name_overrides'] -> «Экспертиза направления. Все кейса»).
 * Без переопределения основная копия называется, как исходник (имя исходника в
 * presentations-public не занято — там лежат только переименованные полные копии).
 *
 * Файл пересобирается при каждом вызове команды (без кеша и проверки свежести): ссылка в
 * чате должна вести к копии, собранной из актуального каталога, а prune быстрая. Запись
 * идёт во временный файл с последующим rename() — параллельный запуск команды или обновление
 * «поверх» не оставляет в public-каталоге битого архива.
 *
 * PublicCopyBuilder::rebuildAll() при очистке public-каталога оставляет основные презентации
 * (передача имён — через второй параметр) нетронутыми: иначе очередная пересборка «парадных»
 * копий удалила бы основную презентацию, а ссылка в чате перестала бы работать.
 */
class MainPresentationBuilder
{
    public function __construct(
        private SlideCloner $slideCloner,
        private LocalPresentationsClient $presentations,
        private string $publicDir,
        /** @var array<string, string> Исходник -> имя основной презентации */
        private array $nameOverrides = [],
    ) {
    }

    /**
     * Собирает «основную» презентацию по заданному исходнику и возвращает путь к файлу
     * в каталоге публичных копий (presentations-public/).
     *
     * @param int[] $mainSlideNumbers Номера слайдов с тегом «основной» (1-базные, из каталога).
     * @param int[] $notCaseSlideNumbers Номера слайдов «не кейс» из каталога (обложка, о компании,
     *        контакты) — всегда остаются в основной презентации.
     */
    public function build(string $sourceFileId, array $mainSlideNumbers, array $notCaseSlideNumbers = []): string
    {
        $keepNumbers = array_values(array_unique(array_merge(
            array_map('intval', $notCaseSlideNumbers),
            array_map('intval', $mainSlideNumbers)
        )));
        sort($keepNumbers);

        if ($keepNumbers === []) {
            throw new RuntimeException("Нечего собирать: для «{$sourceFileId}» не выбрано ни одного слайда");
        }

        if (!is_dir($this->publicDir)) {
            mkdir($this->publicDir, 0775, true);
        }

        $publicDir = rtrim($this->publicDir, '/\\');
        $target = $publicDir . '/' . $this->deckName($sourceFileId);
        // prune вместо clone: пакет исходника остаётся исходным (PowerPoint открывает без
        // ремонта), из него удаляются слайды, не попавшие в «основное» (см. SlideCloner).
        // Заметки/комментарии вычищаются самим prune — наружу служебная разметка не уходит.
        $temporary = $publicDir . '/.' . uniqid('MainDeck-', true) . '.tmp';
        try {
            $this->slideCloner->prune($this->presentations->getFilePath($sourceFileId), $keepNumbers, $temporary);
            rename($temporary, $target);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $target;
    }

    /**
     * Постоянное имя основной презентации по имени исходника (MainPresentationCommand):
     * из $nameOverrides (config['presentations']['main_name_overrides']), без переопределения —
     * имя исходника (оно в presentations-public не занято, см. докблок класса).
     */
    public function deckName(string $sourceFileId): string
    {
        $sourcePath = $this->presentations->getFilePath($sourceFileId);
        $name = basename($this->nameOverrides[basename($sourcePath)]
            ?? pathinfo($sourcePath, PATHINFO_FILENAME) . '.pptx');

        return str_ends_with(mb_strtolower($name), '.pptx') ? $name : $name . '.pptx';
    }
}
