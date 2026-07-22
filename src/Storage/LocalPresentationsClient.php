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
    public function __construct(
        private string $folderPath,
        private string $httpBaseUrl = '',
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

    /** Прямая HTTP-ссылка на файл (см. docker/entrypoint.sh — presentations/ отдаётся на :8080). */
    public function getPublicUrl(string $id): string
    {
        $path = $this->getFilePath($id);

        return rtrim($this->httpBaseUrl, '/') . '/' . rawurlencode(basename($path));
    }
}
