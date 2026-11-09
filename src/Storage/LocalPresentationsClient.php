<?php

declare(strict_types=1);

namespace CasesBot\Storage;

/**
 * Источник презентаций — локальная папка на сервере (временная замена GoogleDriveClient,
 * см. §6.2 ТЗ: та же роль — предоставить Indexer'у список презентаций и путь к файлу для чтения).
 * Файлы уже лежат на диске, поэтому скачивание не нужно — только листинг и резолв пути по имени.
 */
class LocalPresentationsClient
{
    /**
     * $nameOverrides — имя исходника -> имя публичной копии (config['presentations']['public_name_overrides']):
     * getPublicUrl() должен собирать ссылку на имя копии в presentations-public/, а не на имя
     * исходника на Drive (см. PublicCopyBuilder).
     */
    public function __construct(
        private string $folderPath,
        private string $httpBaseUrl = '',
        private array $nameOverrides = [],
    ) {
    }

    /**
     * Презентации (.pptx) в папке: id (имя файла), имя, дата изменения, размер в байтах.
     *
     * @return array<int, array{id: string, name: string, modifiedTime: string, size: int}>
     */
    public function listPresentations(): array
    {
        $base = rtrim($this->folderPath, '/\\');
        $files = [];

        foreach (glob($base . '/*.pptx') ?: [] as $path) {
            if (str_starts_with(basename($path), '~$')) {
                continue; // временный lock-файл открытого в PowerPoint документа, не презентация
            }

            $mtime = filemtime($path);
            $size = filesize($path);
            $files[] = [
                'id' => basename($path),
                'name' => basename($path),
                'modifiedTime' => date(DATE_ATOM, $mtime !== false ? $mtime : time()),
                'size' => $size !== false ? $size : 0,
            ];
        }

        return $files;
    }

    /** Абсолютный путь к презентации по id (имени файла из listPresentations()). */
    public function getFilePath(string $id): string
    {
        $base = rtrim($this->folderPath, '/\\');
        $path = $base . '/' . basename($id);

        if (!is_file($path)) {
            throw new \RuntimeException("Презентация не найдена: {$id}");
        }

        return $path;
    }

    /** Удаляет презентацию по id (имени файла из listPresentations()). */
    public function deleteFile(string $id): void
    {
        $path = $this->getFilePath($id);

        if (!unlink($path)) {
            throw new \RuntimeException("Не удалось удалить презентацию: {$id}");
        }
    }

    /**
     * Прямая HTTP-ссылка на публичную копию файла (см. docker/entrypoint.sh — presentations-public/,
     * «парадные» копии без заметок/комментариев, отдаются на :8080). Имя копии берётся из
     * nameOverrides, если для этого исходника оно задано.
     */
    public function getPublicUrl(string $id): string
    {
        return $this->getPublicUrlByName($this->getPublicName($id));
    }

    /**
     * «Парадное» имя публичной копии исходника (nameOverrides, см. PublicCopyBuilder) —
     * имя файла, под которым копия отдаётся в чат (для текущего каталога —
     * «Экспертиза направления. Все кейса.pptx»).
     */
    public function getPublicName(string $id): string
    {
        $path = $this->getFilePath($id);

        return basename($this->nameOverrides[basename($path)] ?? basename($path));
    }

    /**
     * Прямая HTTP-ссылка на файл в public-каталоге по имени файла (не обязательно имени
     * исходника) — для производных копий, собираемых ботом (MainPresentationBuilder).
     */
    public function getPublicUrlByName(string $publicName): string
    {
        return rtrim($this->httpBaseUrl, '/') . '/' . rawurlencode(basename($publicName));
    }
}
