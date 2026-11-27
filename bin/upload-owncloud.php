<?php

declare(strict_types=1);

/**
 * Ручная выгрузка презентаций в ownCloud (OwnCloudClient, WebDAV PUT, с заменой прежних).
 *
 * bin/upload-owncloud.php           — проверить соединение и выгрузить все pptx из presentations/;
 * bin/upload-owncloud.php --check   — только проверить соединение (PROPFIND корня WebDAV);
 * bin/upload-owncloud.php <файл>…   — выгрузить только перечисленные pptx из presentations/.
 *
 * Ночное окно bin/sync.php выгружает файлы после скачивания из Drive само; этот скрипт —
 * для «прямо сейчас»: поправил презентацию локально / не дожидаясь окна.
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Storage\OwnCloudClient;

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

$owncloud = new OwnCloudClient(
    $config['owncloud']['base_url'],
    $config['owncloud']['username'],
    $config['owncloud']['password']
);

if (!$owncloud->isConfigured()) {
    fwrite(STDERR, "owncloud-upload: OWNCLOUD_USERNAME / OWNCLOUD_PASSWORD не заданы в .env.\n");
    exit(1);
}

try {
    $owncloud->verifyConnection();
} catch (Throwable $e) {
    fwrite(STDERR, 'owncloud-upload: соединение не проверено: ' . $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, "owncloud-upload: соединение с WebDAV в порядке.\n");

if (in_array('--check', $argv, true)) {
    exit(0);
}

$sourcesDir = rtrim($config['presentations']['folder_path'], '/\\');
$targets = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $arg): bool => $arg !== '--check'
));

if ($targets === []) {
    $targets = glob($sourcesDir . '/*.pptx') ?: [];
    $targets = array_map(basename(...), $targets);
}

if ($targets === []) {
    fwrite(STDERR, "owncloud-upload: в {$sourcesDir} нет pptx и файлы не указаны.\n");
    exit(1);
}

$exitCode = 0;
foreach ($targets as $name) {
    try {
        $bytes = $owncloud->putFile(
            $sourcesDir . '/' . $name,
            $config['owncloud']['directory'] . '/' . basename($name)
        );
        fwrite(STDOUT, sprintf("owncloud-upload: «%s» выгружена (%s, %.1f МБ).\n",
            $name, $config['owncloud']['directory'], $bytes / 1048576));
    } catch (Throwable $e) {
        fwrite(STDERR, 'owncloud-upload: ' . $e->getMessage() . "\n");
        $exitCode = 1;
    }
}

exit($exitCode);
