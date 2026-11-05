<?php

declare(strict_types=1);

/**
 * Недельная синхронизация с Google Drive (§77 ТЗ: автообновление каталога).
 *
 * Цикл раз в неделю (SYNC_INTERVAL_SECONDS, по умолчанию 604800 = 7 дней; цикл живёт в
 * docker/entrypoint.sh), а также вручную: php bin/sync.php. Логика идемпотентна:
 *  1) для каждой презентации из GOOGLE_PRESENTATION_ID проверяем modifiedTime/md5 в Drive;
 *  2) если изменилась с прошлого запуска (state в storage/catalog/drive-sync.json — bind-mount
 *     storage/catalog, синхронизируем и при рестарте контейнера) — скачиваем pptx в
 *     presentations/ под именем из Drive (tmp->rename, чтобы не отдавать недокачанный файл);
 *  3) при изменениях — переиндексация каталога (Reindexer, тот же runAutomatic, что в
 *     веб-загрузчике) и пересборка публичных копий (PublicCopyBuilder — то, что отдаётся
 *     по :8080 в чат); иначе — ничего.
 *
 * Флаги: --skip-reindex (не трогать каталог, только скачать + пересобрать публичные копии),
 * --force (скачать и пересобрать, даже если Drive-версия не менялась).
 *
 * Эксперт при этом не делает ничего: поменял презентацию в Google Slides — раз в неделю
 * она сама придёт на сервер, каталог и копии обновятся.
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Catalog\Reindexer;
use CasesBot\Presentation\PublicCopyBuilder;
use CasesBot\Presentation\SlideCloner;
use CasesBot\Storage\GoogleDriveClient;

$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (getenv($key) === false) {
            putenv($key . '=' . trim($value));
        }
    }
}

$config = require __DIR__ . '/../config/config.php';

/** Записывает итог прогона в sync_status_path (drive-sync-status.json) — его показывает upload.php. */
function writeSyncStatus(array $config, array $changed, array $errors): void
{
    $statusPath = $config['google']['sync_status_path'] ?? '';
    if ($statusPath === '' || !is_writable(dirname($statusPath))) {
        return;
    }

    $status = match (true) {
        $errors !== [] => 'error',
        $changed === [] => 'no_changes',
        default => 'ok',
    };

    try {
        @file_put_contents(
            $statusPath,
            json_encode(
                ['status' => $status, 'run_at' => date(DATE_ATOM), 'downloaded' => $changed, 'errors' => $errors],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            )
        );
    } catch (Throwable) {
        // Статус — вспомогательная информация: неудачная запись не должна ломать сам sync.
    }
}

$skipReindex = in_array('--skip-reindex', $argv, true);
$force = in_array('--force', $argv, true);

$presentationIds = $config['google']['presentation_ids'];
if ($presentationIds === []) {
    fwrite(STDERR, "sync: GOOGLE_PRESENTATION_ID не задан в .env — синхронизировать нечего.\n");
    exit(1);
}

$accountJson = $config['google']['service_account_json'];
if ($accountJson === '' || !is_file($accountJson)) {
    fwrite(STDERR, "sync: ключ сервисного аккаунта не найден (GOOGLE_SERVICE_ACCOUNT_JSON = «{$accountJson}»).\n");
    exit(1);
}

$drive = new GoogleDriveClient($accountJson, $config['google']['token_cache_path']);
$sourcesDir = rtrim($config['presentations']['folder_path'], '/\\');

/** @var array<string, array{name: string, modifiedTime: string, md5Checksum: ?string, downloaded_at: string}> $state */
$state = [];
if (is_file($config['google']['sync_state_path'])) {
    $decoded = json_decode((string) file_get_contents($config['google']['sync_state_path']), true);
    if (is_array($decoded['files'] ?? null)) {
        $state = $decoded['files'];
    }
}

$changed = [];
$errors = [];

foreach ($presentationIds as $fileId) {
    try {
        $info = $drive->getFileInfo($fileId);
    } catch (Throwable $e) {
        $errors[] = "Drive {$fileId}: {$e->getMessage()}";
        continue;
    }

    $previous = $state[$fileId] ?? null;
    $unchanged = $previous !== null
        && $previous['modifiedTime'] === $info['modifiedTime']
        && ($previous['md5Checksum'] ?? null) === ($info['md5Checksum'] ?? null);

    if ($unchanged && !$force) {
        fwrite(STDOUT, "sync: «{$info['name']}» без изменений (modifiedTime {$info['modifiedTime']}).\n");
        continue;
    }

    $name = $info['name'];
    if (!str_ends_with(strtolower($name), '.pptx')) {
        $name .= '.pptx';
    }
    $destination = $sourcesDir . '/' . basename($name);
    $tmp = $destination . '.part';

    try {
        $bytes = $drive->downloadAsPptx($fileId, $info['mimeType'], $tmp);
        rename($tmp, $destination);
    } catch (Throwable $e) {
        @unlink($tmp);
        $errors[] = "Скачивание «{$name}» ({$fileId}): {$e->getMessage()}";
        continue;
    }

    $state[$fileId] = [
        'name' => $name,
        'modifiedTime' => $info['modifiedTime'],
        'md5Checksum' => ($info['md5Checksum'] ?? '') !== '' ? $info['md5Checksum'] : null,
        'downloaded_at' => date(DATE_ATOM),
    ];

    $changed[] = $name;
    fwrite(STDOUT, sprintf("sync: скачали «%s» (%.1f МБ, Drive-версия от %s).\n",
        $name, $bytes / 1048576, $info['modifiedTime']));
}

// Сохраняем state только при успешном скачивании хотя бы одного файла — иначе
// изменившаяся версия Drive не будет перекачана на следующим запуске.
if ($changed !== [] && is_writable(dirname($config['google']['sync_state_path']))) {
    @file_put_contents(
        $config['google']['sync_state_path'],
        json_encode(['files' => $state], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
    );
}

if ($changed !== [] && !$skipReindex) {
    fwrite(STDOUT, "sync: переиндексация каталога (может занять несколько минут)...\n");
    try {
        $report = Reindexer::fromConfig($config)->runAutomatic();
        fwrite(STDOUT, "sync: индексировано кейс(ов): " . count($report['indexed']) . "\n");
        foreach (array_unique($report['llmErrors']) as $llmError) {
            fwrite(STDOUT, "sync: LLM: {$llmError}\n");
        }
    } catch (Throwable $e) {
        $errors[] = "Переиндексация: {$e->getMessage()}";
    }
}

if ($changed !== []) {
    try {
        $publicReport = (new PublicCopyBuilder(
            new SlideCloner($config['python']['bin'], $config['python']['slide_cloner']),
            $config['presentations']['folder_path'],
            $config['presentations']['public_folder_path']
        ))->rebuildAll($config['presentations']['public_name_overrides'] ?? []);
        fwrite(STDOUT, 'sync: публичные копии пересобраны: ' . count($publicReport['built']) . " шт.\n");
        foreach ($publicReport['errors'] as $publicError) {
            $errors[] = "Публичная копия: {$publicError}";
        }
    } catch (Throwable $e) {
        $errors[] = "Публичные копии: {$e->getMessage()}";
    }
}

// Итог прогона — в файл статуса (его показывает веб-загрузчик), независимо от того, были ошибки или нет.
writeSyncStatus($config, $changed, $errors);

if ($errors !== []) {
    fwrite(STDERR, "sync: ошибки:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, 'sync: готово' . ($changed === [] ? ' — изменений в Google Drive нет.' : ".\n"));
