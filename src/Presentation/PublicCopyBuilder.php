<?php

declare(strict_types=1);

namespace CasesBot\Presentation;

/**
 * «Парадные» копии презентаций для прямых ссылок из чата (PRESENTATIONS_HTTP_BASE_URL на :8080).
 *
 * Сырой исходник после индексации оставлять клиенту нельзя: в заметках докладчика лежат
 * #кейс# и теги эксперта, а Google Slides при экспорте в pptx переносит и комментарии
 * рецензентов (ppt/comments/*.xml + ppt/commentAuthors.xml). Поэтому после каждого обновления
 * источника (веб-загрузчик, позже — sync из Google Drive) здесь пересобирается вторая копия:
 * те же слайды через SlideCloner::cloneAll() с полной санацией — заметки/комментарии не
 * копируются вовсе, слайды/layout/master/fonts/media не тронуты, порядок сохранён.
 * Копия носит то же имя файла, что исходник, — LocalPresentationsClient::getPublicUrl()
 * продолжает собирать ссылку без изменений, а отдавать её по HTTP должен именно доверенный
 * каталог public-копий (см. docker/entrypoint.sh).
 *
 * Вызовы: bin/build-public.php (CLI), после переиндексации в public/upload.php
 * и позже — sync-шаг из Google Drive.
 */
class PublicCopyBuilder
{
    public function __construct(
        private SlideCloner $slideCloner,
        private string $sourceDir,
        private string $publicDir,
    ) {
    }

    /**
     * Пересобирает публичные копии презентаций источника.
     *
     * $nameOverrides — имя исходника -> имя копии (config['presentations']['public_name_overrides']):
     * копия из презентации Google Drive отдаётся в чат под «парадным» именем, а не под именем
     * файла на Drive. $preserveNames — имена файлов, которые очистка public-каталога не
     * удаляет: основные презентации направления (config['presentations']['main_name_overrides'],
     * MainPresentationBuilder, команда /main_presentation). Всё, что оказалось в public-каталоге,
     * но не является актуальной копией (старые версии, копии удалённых/переименованных
     * исходников, прежние имена), удаляется — в папке остаются ровно актуальный набор
     * полных копий и основные презентации.
     *
     * @param array<string, string> $nameOverrides
     * @param array<int, string> $preserveNames
     * @return array{built: array<int, string>, removed: array<int, string>, errors: array<int, string>}
     */
    public function rebuildAll(array $nameOverrides = [], array $preserveNames = []): array
    {
        $built = [];
        $errors = [];

        $sources = [];
        foreach (glob(rtrim($this->sourceDir, '/\\') . '/*.pptx') ?: [] as $path) {
            $name = basename($path);
            if (str_starts_with($name, '~$')) {
                continue; // временный lock-файл открытого в PowerPoint документа
            }
            $sources[$name] = $path;
        }

        // Имена копий (после оверрайдов) — источник истины для очистки папки. basename()
        // не даёт пробиться traversal-имени из конфига; одинаковые имена у разных исходников —
        // ошибка конфигурации, а не молчаливая перезапись.
        $targetNames = [];
        foreach (array_keys($sources) as $sourceName) {
            $targetName = basename($nameOverrides[$sourceName] ?? $sourceName);
            if (isset($targetNames[$targetName]) && $targetNames[$targetName] !== $sourceName) {
                $errors[] = "Два исходника претендуют на одну публичную копию «{$targetName}»: "
                    . "{$targetNames[$targetName]} и {$sourceName}";
                continue;
            }
            $targetNames[$targetName] = $sourceName;
        }

        foreach ($targetNames as $targetName => $sourceName) {
            $sourcePath = $sources[$sourceName];
            $target = rtrim($this->publicDir, '/\\') . '/' . $targetName;
            $needsRebuild = !is_file($target) || filemtime($sourcePath) > filemtime($target);

            if (!$needsRebuild) {
                continue;
            }

            try {
                $this->slideCloner->cloneAll($sourcePath, $target);
                $built[] = $targetName;
            } catch (\Throwable $e) {
                // Не прерываем обработку остальных презентаций — сообщение уйдёт в отчёт.
                $errors[] = "{$sourceName}: {$e->getMessage()}";
            }
        }

        $removed = [];
        foreach (glob(rtrim($this->publicDir, '/\\') . '/*.pptx') ?: [] as $path) {
            $name = basename($path);
            if (isset($targetNames[$name])) {
                continue;
            }
            // Основные презентации направления собирает MainPresentationBuilder
            // (/main_presentation) — тоже легальные жители public-каталога: ссылка на них
            // из чата должна переживать пересборку «парадных» копий. Их жизненным циклом
            // управляет сам сборщик, не эта очистка.
            if (in_array($name, array_map('basename', $preserveNames), true)) {
                continue;
            }
            @unlink($path);
            $removed[] = $name;
        }

        return ['built' => $built, 'removed' => $removed, 'errors' => $errors];
    }

    /** Удаляет публичную копию презентации (при удалении исходника через веб-загрузчик). */
    public function delete(string $sourceFileName): void
    {
        $target = rtrim($this->publicDir, '/\\') . '/' . basename($sourceFileName);
        if (is_file($target) && !@unlink($target)) {
            throw new \RuntimeException("Не удалось удалить публичную копию «{$target}».");
        }
    }
}
